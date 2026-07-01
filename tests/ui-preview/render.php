<?php
/**
 * Dev-only UI preview: renders the real checkout payment panel (via
 * Gateway::payment_fields()) into a static HTML page with the real CSS and
 * JS, backed by a mocked AJAX endpoint. Lets you eyeball / screenshot the UI
 * without a WordPress install.
 *
 * Usage:
 *   php tests/ui-preview/render.php > tests/ui-preview/index.html
 *   python3 -m http.server 8931   # from the repo root
 *   open http://localhost:8931/tests/ui-preview/index.html
 */

if ( ! defined( 'MTFWC_VERSION' ) ) { define( 'MTFWC_VERSION', 'preview' ); }
if ( ! defined( 'MTFWC_PLUGIN_URL' ) ) { define( 'MTFWC_PLUGIN_URL', '../../' ); }

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
function wp_salt( $scheme = '' ) { return 'preview-salt'; }
function get_option( $key, $default = array() ) { return array( 'default_terminal_id' => 'term_event_stand', 'description' => 'Pay in person using Mollie Terminal.' ); }
function admin_url( $path = '' ) { return 'mock-ajax'; }
function add_query_arg( array $args, $url ) { return $url . '?' . http_build_query( $args ); }
function woocommerce_pos_request( $type = 'all' ) { return true; }

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

class FakeOrderForPreview {
	public function get_id() { return 6915; }
}
function wc_get_order( $order_id ) { return new FakeOrderForPreview(); }

$GLOBALS['wp'] = (object) array( 'query_vars' => array( 'order-pay' => 6915 ) );

require_once __DIR__ . '/../../includes/Settings.php';
require_once __DIR__ . '/../../includes/AjaxHandler.php';
require_once __DIR__ . '/../../includes/Gateway.php';

use WCPOS\WooCommercePOS\MollieTerminal\Gateway;

$gateway = new Gateway();
ob_start();
$gateway->payment_fields();
$panel = ob_get_clean();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Mollie Terminal — payment panel preview</title>
<link rel="stylesheet" href="../../assets/css/payment.css">
<style>
	/* Approximation of the POS checkout modal so the panel is previewed in context. */
	body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #64748b; }
	.pos-modal { max-width: 560px; margin: 2rem auto; background: #fff; border-radius: 10px; padding: 1.25rem 1.5rem 5rem; box-shadow: 0 20px 60px rgba(0,0,0,.35); }
	.pos-modal h2 { margin: 0 0 1rem; font-size: 18px; text-align: center; }
	.pos-amount { text-align: center; font-weight: 700; margin-bottom: 1rem; border-bottom: 1px solid #e2e8f0; padding-bottom: 1rem; }
	.pos-method { font-weight: 600; margin: 1rem 0 .25rem; }
	.button { display: inline-block; padding: .45rem .9rem; border: 1px solid #cbd5e1; border-radius: 6px; background: #f8fafc; cursor: pointer; font-size: 13px; }
	.button.button-primary { background: #2271b1; border-color: #2271b1; color: #fff; }
	.preview-controls { max-width: 560px; margin: 0 auto 2rem; background: #0f172a; color: #e2e8f0; border-radius: 10px; padding: .75rem 1rem; font-size: 12px; display: flex; gap: .5rem; flex-wrap: wrap; align-items: center; }
	.preview-controls button { font-size: 12px; padding: .3rem .6rem; }
</style>
</head>
<body>
<div class="pos-modal">
	<h2>Order afrekenen #6915</h2>
	<div class="pos-amount">Te betalen bedrag: € 2,99</div>
	<div class="pos-method">Mollie Terminal</div>
	<?php echo $panel; ?>
</div>
<div class="preview-controls">
	<strong>Mock backend:</strong>
	<button type="button" onclick="window.mtfwcMock.mode='paid'">next poll: paid</button>
	<button type="button" onclick="window.mtfwcMock.mode='failed'">next poll: failed</button>
	<button type="button" onclick="window.mtfwcMock.mode='pending'">polls stay pending</button>
	<span>current: <code id="mock-mode">pending</code></span>
</div>
<script>
	window.mtfwcPaymentData = {
		ajaxUrl: 'mock-ajax',
		defaultTerminalId: 'term_event_stand',
		pollIntervalMs: 1500,
		pollTimeoutMs: 300000,
		i18n: {}
	};
	window.mtfwcMock = { mode: 'pending' };
	setInterval(function () {
		document.getElementById('mock-mode').textContent = window.mtfwcMock.mode;
	}, 300);
	// Mock the plugin's AJAX endpoint.
	window.fetch = function (url, options) {
		var action = options && options.body ? options.body.get('action') : '';
		var data = {};
		if ('mtfwc_list_terminals' === action) {
			data = { terminals: [
				{ id: 'term_event_stand', label: 'Event stand', status: 'active' },
				{ id: 'term_front_desk', label: 'Front desk', status: 'active' },
				{ id: 'term_warehouse', label: 'Warehouse (backup)', status: 'active' }
			], default_terminal_id: 'term_event_stand' };
		} else if ('mtfwc_start_payment' === action) {
			window.mtfwcMock.mode = 'pending';
			data = { status: 'created', payment: { id: 'tr_preview', status: 'open' } };
		} else if ('mtfwc_poll_payment' === action) {
			if ('paid' === window.mtfwcMock.mode) {
				data = { status: 'paid', redirect_url: '#thank-you-page' };
			} else if ('failed' === window.mtfwcMock.mode) {
				data = { status: 'failed' };
			} else {
				data = { status: 'pending' };
			}
		} else if ('mtfwc_cancel_payment' === action) {
			data = { status: 'canceled' };
		}
		return new Promise(function (resolve) {
			setTimeout(function () {
				resolve({ ok: true, status: 200, text: function () { return Promise.resolve(JSON.stringify({ success: true, data: data })); } });
			}, 350);
		});
	};
</script>
<script src="../../assets/js/payment.js"></script>
</body>
</html>
