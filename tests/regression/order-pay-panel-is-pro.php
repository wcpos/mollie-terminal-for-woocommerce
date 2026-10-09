<?php
// The POS order-pay page is Pro's shared panel: the gateway prints its description and hands
// the order over, and enqueues nothing. Under the QR carve-out (an on-screen QR method enabled)
// the old panel renders and its script is enqueued on the order-pay page alone.
function expect( $condition, $message = 'expectation failed' ) { if ( ! $condition ) { fwrite( STDERR, $message . "\n" ); exit( 1 ); } }

if ( ! defined( 'MTFWC_VERSION' ) ) { define( 'MTFWC_VERSION', '1.0.0-test' ); }
if ( ! defined( 'MTFWC_PLUGIN_URL' ) ) { define( 'MTFWC_PLUGIN_URL', 'https://shop.example/plugins/mollie/' ); }
function __( $text, $domain = null ) { return $text; }
function esc_html__( $text, $domain = null ) { return $text; }
function esc_attr__( $text, $domain = null ) { return $text; }
function esc_html( $text ) { return $text; }
function esc_attr( $text ) { return $text; }
function wp_kses_post( $text ) { return $text; }
function apply_filters( $hook, $value ) { return $value; }
function add_action() {}
function absint( $value ) { return abs( (int) $value ); }
function admin_url( $path = '' ) { return 'https://shop.example/wp-admin/' . $path; }
function add_query_arg( $args, $url = '' ) { return $url; }
function wp_hash( $data ) { return hash( 'sha256', $data ); }
function wp_salt( $scheme = '' ) { return 'salt'; }
function get_option( $key, $default = false ) { return $GLOBALS['options'][ $key ] ?? $default; }
function is_checkout_pay_page() { return $GLOBALS['pay_page']; }
function wc_get_order( $id ) { return 123 === (int) $id ? $GLOBALS['order'] : false; }
function wcpos_pro_order_pay_panel( $gateway, $order ) { $GLOBALS['panel'][] = array( $gateway, $order ); echo '<div id="wcpos-pro-order-pay"></div>'; }
function wp_enqueue_script( $handle ) { $GLOBALS['enqueued'][] = $handle; }
function wp_enqueue_style( $handle ) { $GLOBALS['enqueued'][] = $handle; }
function wp_localize_script() {}

class WC_Order {
	public function get_id() { return 123; }
	public function is_paid() { return false; }
	public function get_meta( $key ) { return null; }
}
class WC_Payment_Gateway {
	public $id = 'mollie_terminal_for_woocommerce';
	public function get_option( $key, $default = '' ) { return 'description' === $key ? 'Pay on the Mollie terminal.' : $default; }
}

require_once __DIR__ . '/../../includes/Settings.php';
require_once __DIR__ . '/../../includes/PaymentAttempt.php';
require_once __DIR__ . '/../../includes/AjaxHandler.php';
require_once __DIR__ . '/../../includes/Gateway.php';

$gateway = ( new ReflectionClass( WCPOS\WooCommercePOS\MollieTerminal\Gateway::class ) )->newInstanceWithoutConstructor();
$GLOBALS['order'] = new WC_Order();
$GLOBALS['wp']    = (object) array( 'query_vars' => array( 'order-pay' => 123 ) );

function render( array $settings ): string {
	$GLOBALS['options'] = array( 'woocommerce_mollie_terminal_for_woocommerce_settings' => $settings );
	$GLOBALS['panel']   = array();
	ob_start();
	$GLOBALS['gateway']->payment_fields();
	return ob_get_clean();
}
function enqueue( array $settings, bool $pay_page ): array {
	$GLOBALS['options']  = array( 'woocommerce_mollie_terminal_for_woocommerce_settings' => $settings );
	$GLOBALS['pay_page'] = $pay_page;
	$GLOBALS['enqueued'] = array();
	$GLOBALS['gateway']->enqueue_payment_scripts();
	return $GLOBALS['enqueued'];
}
$GLOBALS['gateway'] = $gateway;

// No QR method enabled: Pro's panel, nothing else, nothing enqueued.
$html = render( array( 'default_terminal_id' => 'term_1' ) );
expect( '<p>Pay on the Mollie terminal.</p><div id="wcpos-pro-order-pay"></div>' === $html, 'the description precedes Pro\'s panel and nothing else is printed' );
expect( array( array( $gateway, $GLOBALS['order'] ) ) === $GLOBALS['panel'], 'Pro receives the gateway and the order' );
expect( array() === enqueue( array(), true ), 'nothing is enqueued for Pro\'s panel, even on the order-pay page' );

$GLOBALS['wp'] = (object) array( 'query_vars' => array() );
render( array() );
expect( array() === $GLOBALS['panel'], 'without an order on the page nothing is handed to Pro' );
$GLOBALS['wp'] = (object) array( 'query_vars' => array( 'order-pay' => 123 ) );

// QR carve-out: the old panel renders, Pro is not asked, the script is enqueued on the pay page only.
$html = render( array( 'qr_methods' => array( 'ideal' ), 'default_terminal_id' => 'term_1' ) );
expect( false !== strpos( $html, 'mtfwc-payment-interface' ) && false !== strpos( $html, 'data-channel="qr"' ), 'with a QR method enabled the old panel renders with its QR channel' );
expect( array() === $GLOBALS['panel'], 'under the carve-out Pro\'s panel is not rendered' );
expect( array( 'mtfwc-payment', 'mtfwc-payment' ) === enqueue( array( 'qr_methods' => array( 'bancontact' ) ), true ), 'the old panel\'s script and style are enqueued on the order-pay page' );
expect( array() === enqueue( array( 'qr_methods' => array( 'bancontact' ) ), false ), 'nothing is enqueued off the order-pay page' );

echo "order-pay-panel-is-pro ok\n";
