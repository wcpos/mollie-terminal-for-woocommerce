<?php
// The order-pay form submit: a paid order short-circuits to the POS-aware thank-you page; an
// unpaid one is Pro's reading of the ledger; under the QR carve-out the old path answers.
function expect( $condition, $message = 'expectation failed' ) { if ( ! $condition ) { fwrite( STDERR, $message . "\n" ); exit( 1 ); } }

function __( $text, $domain = null ) { return $text; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function add_action() {}
function get_option( $key, $default = false ) { return $GLOBALS['options'][ $key ] ?? $default; }
function wc_get_order( $id ) { return 123 === (int) $id ? $GLOBALS['order'] : false; }
function wcpos_pro_order_pay_process( $order ) { $GLOBALS['pro_calls'][] = $order; return array( 'result' => 'failure' ); }
function wc_add_notice( $message, $type = 'notice' ) { $GLOBALS['notices'][] = $type; }
class SilentLoggerForProcessPayment { public function log( $level, $message, $context = array() ) {} }
function wc_get_logger() { return new SilentLoggerForProcessPayment(); }
function woocommerce_pos_request() { return true; }
function get_home_url( $blog, $path ) { return 'https://shop.example' . $path; }
function add_query_arg( $args, $url ) { return $url . '?key=' . $args['key']; }

class WC_Payment_Gateway {}
class WC_Order {
	public $paid = false;
	public function get_id() { return 123; }
	public function is_paid() { return $this->paid; }
	public function get_order_key() { return 'wc_order_k'; }
	public function get_meta( $key ) { return null; }
}

require_once __DIR__ . '/../../includes/Settings.php';
require_once __DIR__ . '/../../includes/Logger.php';
require_once __DIR__ . '/../../includes/PaymentAttempt.php';
require_once __DIR__ . '/../../includes/PaymentLock.php';
require_once __DIR__ . '/../../includes/Utils/Money.php';
require_once __DIR__ . '/../../includes/PaymentReconciler.php';
require_once __DIR__ . '/../../includes/Services/MollieApiClient.php';
require_once __DIR__ . '/../../includes/Services/TerminalService.php';
require_once __DIR__ . '/../../includes/Services/MolliePaymentService.php';
require_once __DIR__ . '/../../includes/AjaxHandler.php';
require_once __DIR__ . '/../../includes/Gateway.php';

$gateway = ( new ReflectionClass( WCPOS\WooCommercePOS\MollieTerminal\Gateway::class ) )->newInstanceWithoutConstructor();
$GLOBALS['options'] = array( 'woocommerce_mollie_terminal_for_woocommerce_settings' => array( 'api_key' => 'live_k' ) );
$GLOBALS['pro_calls'] = array();
$GLOBALS['notices'] = array();

$GLOBALS['order'] = new WC_Order(); $GLOBALS['order']->paid = true;
expect( array( 'result' => 'success', 'redirect' => 'https://shop.example/wcpos-checkout/order-received/123?key=wc_order_k' ) === $gateway->process_payment( 123 ), 'a paid order goes straight to the POS-aware thank-you page' );
expect( array() === $GLOBALS['pro_calls'], 'a paid order never reaches Pro' );

$GLOBALS['order'] = new WC_Order();
expect( array( 'result' => 'failure' ) === $gateway->process_payment( 123 ), 'an unpaid order is answered by Pro' );
expect( array( $GLOBALS['order'] ) === $GLOBALS['pro_calls'] && array() === $GLOBALS['notices'], 'Pro is asked once with the order and the old path adds no notice' );

// QR carve-out: the old path (no attempt: idle, the order is not paid, a notice and failure).
$GLOBALS['options']['woocommerce_mollie_terminal_for_woocommerce_settings']['qr_methods'] = array( 'ideal' );
$GLOBALS['pro_calls'] = array();
expect( array( 'result' => 'failure' ) === $gateway->process_payment( 123 ), 'under the carve-out an unpaid order fails as before' );
expect( array() === $GLOBALS['pro_calls'] && array( 'notice' ) === $GLOBALS['notices'], 'under the carve-out Pro is not asked and the old notice is added' );

expect( array( 'result' => 'failure' ) === $gateway->process_payment( 999 ), 'an unknown order fails' );

echo "process-payment-is-pro ok\n";
