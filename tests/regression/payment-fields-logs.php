<?php
function expect( $condition, $message = 'expectation failed' ) { if ( ! $condition ) { fwrite( STDERR, $message . "\n" ); exit( 1 ); } }

if ( ! defined( 'MTFWC_VERSION' ) ) { define( 'MTFWC_VERSION', '0.1.2-test' ); }
if ( ! defined( 'MTFWC_PLUGIN_URL' ) ) { define( 'MTFWC_PLUGIN_URL', 'https://example.test/wp-content/plugins/mollie-terminal-for-woocommerce/' ); }

function __( $text, $domain = null ) { return $text; }
function esc_html__( $text, $domain = null ) { return $text; }
function esc_attr__( $text, $domain = null ) { return $text; }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function wp_kses_post( $text ) { return $text; }
function apply_filters( $hook, $value ) { return $value; }
function add_action() {}
function absint( $value ) { return abs( (int) $value ); }
function is_checkout_pay_page() { return true; }
function wp_hash( $data ) { return hash( 'sha256', $data ); }
function wp_salt( $scheme = '' ) { return 'test-salt'; }
function get_option( $key, $default = array() ) { return array( 'default_terminal_id' => 'term_default_for_test' ); }
function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . ltrim( $path, '/' ); }
function add_query_arg( array $args, $url ) { return $url . '?' . http_build_query( $args ); }

class WC_Payment_Gateway {
	public $id;
	public $method_title;
	public $method_description;
	public $supports = array();
	public $title;
	public $description;
	public $form_fields = array();
	public function init_settings() {}
	public function init_form_fields() {}
	public function get_option( $key, $default = '' ) { return 'description' === $key ? 'Pay in person using Mollie Terminal.' : $default; }
}

class FakeOrderForPaymentFields {
	public function get_id() { return 123; }
}
function wc_get_order( $order_id ) { return 123 === (int) $order_id ? new FakeOrderForPaymentFields() : null; }

$GLOBALS['wp'] = (object) array( 'query_vars' => array( 'order-pay' => 123 ) );

require_once __DIR__ . '/../../includes/Settings.php';
require_once __DIR__ . '/../../includes/AjaxHandler.php';
require_once __DIR__ . '/../../includes/Gateway.php';

use WCPOS\WooCommercePOS\MollieTerminal\Gateway;

$gateway = new Gateway();
if ( ! method_exists( $gateway, 'payment_fields' ) ) {
	fwrite( STDERR, "Gateway::payment_fields is missing\n" );
	exit( 1 );
}

ob_start();
$gateway->payment_fields();
$html = ob_get_clean();

expect( false !== strpos( $html, 'mtfwc-payment-interface' ), 'payment interface should render' );
expect( false !== strpos( $html, 'mtfwc-payment-log-textarea' ), 'log textarea should render' );
expect( false !== strpos( $html, 'mtfwc-toggle-log' ), 'show logs control should render' );
expect( false !== strpos( $html, 'mtfwc-clear-log' ), 'clear logs control should render' );
expect( false !== strpos( $html, 'mtfwc-copy-log' ), 'copy logs control should render' );
expect( false !== strpos( $html, 'data-order-id="123"' ), 'order-pay controls should include order id' );
expect( 1 === preg_match( '/data-order-token="[^"]+"/', $html ), 'order-pay controls should include a non-empty order token' );
expect( false !== strpos( $html, 'term_default_for_test' ), 'payment fields should expose default terminal id' );

echo "payment-fields-logs ok\n";
