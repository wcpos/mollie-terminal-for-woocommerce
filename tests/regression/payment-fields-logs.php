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

$GLOBALS['mtfwc_test_options'] = array( 'default_terminal_id' => 'term_default_for_test', 'show_logs' => 'no' );
function get_option( $key, $default = array() ) { return $GLOBALS['mtfwc_test_options']; }
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
	public $paid = false;
	public $meta = array();
	public function get_id() { return 123; }
	public function is_paid() { return $this->paid; }
	public function get_meta( $key ) { return $this->meta[ $key ] ?? null; }
}
$GLOBALS['mtfwc_test_order'] = new FakeOrderForPaymentFields();
function wc_get_order( $order_id ) { return 123 === (int) $order_id ? $GLOBALS['mtfwc_test_order'] : null; }

$GLOBALS['wp'] = (object) array( 'query_vars' => array( 'order-pay' => 123 ) );

require_once __DIR__ . '/../../includes/Settings.php';
require_once __DIR__ . '/../../includes/PaymentAttempt.php';
require_once __DIR__ . '/../../includes/AjaxHandler.php';
require_once __DIR__ . '/../../includes/Gateway.php';

use WCPOS\WooCommercePOS\MollieTerminal\Gateway;
use WCPOS\WooCommercePOS\MollieTerminal\PaymentAttempt;

function render_fields(): string {
	$gateway = new Gateway();
	ob_start();
	$gateway->payment_fields();
	return ob_get_clean();
}

// --- Default render: logs hidden, single Start button, idle (no resume). -----
$html = render_fields();
expect( false !== strpos( $html, 'mtfwc-payment-interface' ), 'payment interface should render' );
expect( false !== strpos( $html, 'mtfwc-payment-log-textarea' ), 'log textarea should always render (JS writes to it)' );
expect( false !== strpos( $html, 'mtfwc-logging-hidden' ), 'logging section should be hidden by default' );
expect( false === strpos( $html, 'mtfwc-toggle-log' ), 'show logs control should be hidden when show_logs is off' );
expect( false === strpos( $html, 'mtfwc-copy-log' ), 'copy control should be hidden when show_logs is off' );
expect( false === strpos( $html, 'mtfwc-clear-log' ), 'clear control should be hidden when show_logs is off' );
expect( false !== strpos( $html, 'mtfwc-primary-action' ), 'single primary action button should render' );
expect( false === strpos( $html, 'mtfwc-poll-payment' ), 'the standalone Check Status button should be gone' );
expect( false === strpos( $html, 'mtfwc-cancel-payment' ), 'the standalone Cancel button should be gone' );
expect( false !== strpos( $html, 'data-mtfwc-mode="start"' ), 'idle panel primary button should be in start mode' );
expect( false !== strpos( $html, 'data-resume="0"' ), 'a panel with no open attempt should not resume' );
expect( false !== strpos( $html, 'data-gateway-id="mollie_terminal_for_woocommerce"' ), 'panel should expose the gateway id' );
expect( false !== strpos( $html, 'data-order-id="123"' ), 'order-pay controls should include order id' );
expect( 1 === preg_match( '/data-order-token="[^"]+"/', $html ), 'order-pay controls should include a non-empty order token' );
expect( false !== strpos( $html, 'term_default_for_test' ), 'payment fields should expose default terminal id' );

// --- show_logs on: log tools render. -----------------------------------------
$GLOBALS['mtfwc_test_options']['show_logs'] = 'yes';
$html = render_fields();
expect( false !== strpos( $html, 'mtfwc-toggle-log' ), 'show logs control should render when show_logs is on' );
expect( false !== strpos( $html, 'mtfwc-copy-log' ), 'copy control should render when show_logs is on' );
expect( false !== strpos( $html, 'mtfwc-clear-log' ), 'clear control should render when show_logs is on' );
expect( false === strpos( $html, 'mtfwc-logging-hidden' ), 'logging section should not be hidden when show_logs is on' );
$GLOBALS['mtfwc_test_options']['show_logs'] = 'no';

// --- Open attempt: the panel resumes and shows the Cancel button. ------------
$GLOBALS['mtfwc_test_order']->meta[ PaymentAttempt::META_CURRENT_PAYMENT_ID ] = 'tr_open_test';
$GLOBALS['mtfwc_test_order']->meta[ PaymentAttempt::META_CURRENT_PAYMENT_STATUS ] = 'open';
$html = render_fields();
expect( false !== strpos( $html, 'data-resume="1"' ), 'an order with an open attempt should resume on load' );
expect( false !== strpos( $html, 'data-mtfwc-mode="cancel"' ), 'a resuming panel should render the button in cancel mode' );

// --- Paid order: no resume even with a lingering attempt pointer. ------------
$GLOBALS['mtfwc_test_order']->paid = true;
$html = render_fields();
expect( false !== strpos( $html, 'data-resume="0"' ), 'a paid order should never resume the poll loop' );

echo "payment-fields-logs ok\n";
