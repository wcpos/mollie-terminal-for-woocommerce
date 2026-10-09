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
function wcpos_pro_order_pay_panel( $gateway, $order ) { $GLOBALS['panel'][] = array( $gateway, $order ); echo '<div id="wcpos-pro-order-pay"></div>'; }
function wp_enqueue_script( $handle ) { $GLOBALS['enqueued'][] = $handle; }
function wp_enqueue_style( $handle ) { $GLOBALS['enqueued'][] = $handle; }
function wp_localize_script() {}

class WC_Order {
	public $meta = array();
	public function get_id() { return 123; }
	public function is_paid() { return false; }
	public function needs_payment() { return true; }
	public function get_total() { return '10.00'; }
	public function get_currency() { return 'EUR'; }
	public function get_meta( $key ) { return $this->meta[ $key ] ?? null; }
	public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
	public function save() {}
}
class WC_Payment_Gateway {
	public $id = 'mollie_terminal_for_woocommerce';
	public function get_option( $key, $default = '' ) { return 'description' === $key ? 'Pay on the Mollie terminal.' : $default; }
}

require_once __DIR__ . '/support/fake-wpdb.php';
require_once __DIR__ . '/stubs/wcpos-pro-server.php';
require_once __DIR__ . '/stubs/order-lock.php';
function sanitize_key( $key ) { return $key; }
function wp_json_encode( $value ) { return json_encode( $value ); }
class SilentLoggerForPanel { public function log( $level, $message, $context = array() ) {} }
function wc_get_logger() { return new SilentLoggerForPanel(); }
function wcpos_pro_payment_id_for_action( $provider, $ref ) { return null; }
function wcpos_pro_adopt_legacy_attempt( $order, $gateway_id, $ref, $amount, $currency ) { $GLOBALS['adopted'][] = array( $order->get_id(), $ref ); if ( ! empty( $GLOBALS['adopt_throws'] ) ) { throw new RuntimeException( 'boom' ); } return array( 'id' => 'row' ); }
$wpdb = new FakeWpdb();
require_once __DIR__ . '/../../includes/Settings.php';
require_once __DIR__ . '/../../includes/Logger.php';
require_once __DIR__ . '/../../includes/PaymentAttempt.php';
require_once __DIR__ . '/../../includes/PaymentLock.php';
require_once __DIR__ . '/../../includes/Legacy_Adoption.php';
require_once __DIR__ . '/../../includes/AjaxHandler.php';
require_once __DIR__ . '/../../includes/Gateway.php';

$gateway = ( new ReflectionClass( WCPOS\WooCommercePOS\MollieTerminal\Gateway::class ) )->newInstanceWithoutConstructor();
$GLOBALS['order'] = new WC_Order();
$GLOBALS['orders'] = array( 123 => $GLOBALS['order'] );
$GLOBALS['adopted'] = array();
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
expect( array() === $GLOBALS['adopted'], 'an order without an old attempt has nothing to adopt' );
// An open old-panel attempt the pass has not reached is adopted before the panel renders.
$GLOBALS['order']->meta = array( WCPOS\WooCommercePOS\MollieTerminal\PaymentAttempt::META_CURRENT_PAYMENT_ID => 'tr_left', WCPOS\WooCommercePOS\MollieTerminal\PaymentAttempt::META_CURRENT_PAYMENT_METHOD => 'pointofsale', WCPOS\WooCommercePOS\MollieTerminal\PaymentAttempt::META_CURRENT_PAYMENT_STATUS => 'open' );
render( array( 'default_terminal_id' => 'term_1' ) );
expect( array( array( 123, 'tr_left' ) ) === $GLOBALS['adopted'] && 1 === count( $GLOBALS['panel'] ), 'an open old attempt is Pro\'s before Pro\'s panel renders' );
// While a till holds the order the panel waits instead of offering a charge beside an attempt nobody owns yet.
WCPOS\WooCommercePOS\Payments\Contract\Order_Lock::$refuse = array( 123 );
$html = render( array( 'default_terminal_id' => 'term_1' ) );
expect( array() === $GLOBALS['panel'] && false !== strpos( $html, 'Reload the page in a moment' ), 'a refused render-time adoption shows a wait, not Pro\'s panel' );
// Pro refusing or throwing leaves the attempt open and unowned: no panel either, and the page says the
// old sweep cancels it within ten minutes.
WCPOS\WooCommercePOS\Payments\Contract\Order_Lock::$refuse = array();
$GLOBALS['adopt_throws'] = true;
$html = render( array( 'default_terminal_id' => 'term_1' ) );
expect( array() === $GLOBALS['panel'] && false !== strpos( $html, 'cancelled automatically within ten minutes' ), 'a failed adoption shows no panel and says what happens next' );
$GLOBALS['adopt_throws'] = false;
WCPOS\WooCommercePOS\Payments\Contract\Order_Lock::$refuse = array( 123 );
// With nothing to adopt the lock is not even asked for: a held lock on a plain order shows the panel.
$GLOBALS['order']->meta = array();
render( array( 'default_terminal_id' => 'term_1' ) );
expect( 1 === count( $GLOBALS['panel'] ), 'a plain order renders Pro\'s panel without taking the lock' );
WCPOS\WooCommercePOS\Payments\Contract\Order_Lock::$refuse = array();
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
