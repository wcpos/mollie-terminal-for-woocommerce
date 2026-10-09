<?php
// An attempt Pro adopted on upgrade is Pro's leg: the stale-payment sweep neither cancels nor
// completes it, the order-status cleanup leaves it, a tab still on the old panel is refused, and
// the legacy webhook acknowledges its delivery without calling Mollie.
function expect( $condition, $message = 'expectation failed' ) { if ( ! $condition ) { fwrite( STDERR, $message . "\n" ); exit( 1 ); } }
require_once __DIR__ . '/support/fake-wpdb.php';

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) { define( 'MINUTE_IN_SECONDS', 60 ); }
function add_action( $hook, $cb = null, $priority = 10, $args = 1 ) {}
function add_filter( $hook, $cb = null, $priority = 10, $args = 1 ) {}
function apply_filters( $hook, $value ) { return $value; }
function __( $text, $domain = null ) { return $text; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function absint( $value ) { return abs( (int) $value ); }
function sanitize_text_field( $value ) { return (string) $value; }
function wp_unslash( $value ) { return $value; }
function current_user_can( $capability, $object_id = null ) { return true; }
function wp_doing_ajax() { return false; }
function wp_hash( $data ) { return hash( 'sha256', $data ); }
function wp_salt( $scheme = '' ) { return 'salt'; }
function get_option( $key, $default = false ) { return $GLOBALS['options'][ $key ] ?? $default; }
function clean_post_cache( $id ) {}
function wc_get_order( $id ) { return $GLOBALS['order']; }
function wcpos_pro_payment_id_for_action( $provider, $ref ) { return $GLOBALS['adopted_map'][ $ref ] ?? null; }
function wcpos_get_settings( $id, $key = null ) { return array( 'gateways' => array( 'mollie_terminal_for_woocommerce' => array( 'enabled' => true ) ) ); }
function wp_remote_request( $url, $args ) { $GLOBALS['http'][] = $url; return new WP_Error( 'http_request_failed', 'offline' ); }
class SilentLoggerForGuards { public function log( $level, $message, $context = array() ) { $GLOBALS['log'][] = $message; } }
function wc_get_logger() { return new SilentLoggerForGuards(); }
class JsonResponseForGuards extends Error { public $data; public $status; public function __construct( $data, int $status ) { parent::__construct( 'json', $status ); $this->data = $data; $this->status = $status; } }
function wp_send_json_error( $data = null, $status_code = null ) { throw new JsonResponseForGuards( $data, (int) $status_code ); }
function wp_send_json_success( $data = null, $status_code = null ) { throw new JsonResponseForGuards( $data, 200 ); }

require_once __DIR__ . '/stubs/wcpos-pro-server.php';
require_once __DIR__ . '/../../includes/Settings.php';
require_once __DIR__ . '/../../includes/Logger.php';
require_once __DIR__ . '/../../includes/PaymentAttempt.php';
require_once __DIR__ . '/../../includes/PaymentLock.php';
require_once __DIR__ . '/../../includes/Utils/Money.php';
require_once __DIR__ . '/../../includes/PaymentReconciler.php';
require_once __DIR__ . '/../../includes/Services/MollieApiClient.php';
require_once __DIR__ . '/../../includes/Services/TerminalService.php';
require_once __DIR__ . '/../../includes/Services/MolliePaymentService.php';
require_once __DIR__ . '/../../includes/Legacy_Adoption.php';
require_once __DIR__ . '/../../includes/PaymentSweeper.php';
require_once __DIR__ . '/../../includes/PaymentCleanup.php';
require_once __DIR__ . '/../../includes/AjaxHandler.php';
require_once __DIR__ . '/../../includes/WebhookHandler.php';

use WCPOS\WooCommercePOS\MollieTerminal\AjaxHandler;
use WCPOS\WooCommercePOS\MollieTerminal\PaymentAttempt;
use WCPOS\WooCommercePOS\MollieTerminal\PaymentCleanup;
use WCPOS\WooCommercePOS\MollieTerminal\PaymentSweeper;
use WCPOS\WooCommercePOS\MollieTerminal\Services\MolliePaymentService;
use WCPOS\WooCommercePOS\MollieTerminal\WebhookHandler;

$wpdb = new FakeWpdb();
$GLOBALS['options'] = array( 'woocommerce_mollie_terminal_for_woocommerce_settings' => array( 'api_key' => 'live_k' ) );

class FakeOrderForGuards {
	public $meta = array(); public $paid = false; public $notes = array(); public $status = 'processing';
	public function get_id() { return 777; }
	public function is_paid() { return $this->paid; }
	public function get_status() { return $this->status; }
	public function get_meta( $key ) { return $this->meta[ $key ] ?? null; }
	public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
	public function add_order_note( $note ) { $this->notes[] = $note; }
	public function save() {}
	public $payment_method = ''; public $payment_method_title = '';
	public function get_payment_method() { return $this->payment_method; }
	public function set_payment_method( $m ) { $this->payment_method = (string) $m; }
	public function set_payment_method_title( $t ) { $this->payment_method_title = (string) $t; }
}
class CountingServiceForGuards extends MolliePaymentService {
	public $cancels = 0; public $polls = 0;
	public function __construct() {}
	public function cancel_order_payment( $order, string $only_payment_id = '' ): array { $this->cancels++; return array( 'status' => 'canceled' ); }
	public function poll_order( $order, string $source = 'poll' ): array { $this->polls++; return array( 'status' => 'open' ); }
	public function cancel_abandoned_payments( $order ): array { return array(); }
}
function open_attempt( string $payment_id, int $age ): FakeOrderForGuards {
	$order = new FakeOrderForGuards();
	$order->meta = array(
		PaymentAttempt::META_CURRENT_PAYMENT_ID => $payment_id,
		PaymentAttempt::META_CURRENT_PAYMENT_METHOD => 'pointofsale',
		PaymentAttempt::META_CURRENT_PAYMENT_STATUS => 'open',
		PaymentAttempt::META_CURRENT_PAYMENT_CREATED_AT => gmdate( 'c', time() - $age ),
	);
	return $order;
}

// The sweep: an adopted open attempt past the threshold is left alone; an unadopted one is swept.
$GLOBALS['adopted_map'] = array( 'tr_adopted' => 'row-1' );
$service = new CountingServiceForGuards();
$sweeper = new PaymentSweeper( $service );
expect( false === $sweeper->sweep_order( open_attempt( 'tr_adopted', 3600 ) ) && 0 === $service->cancels, 'the sweep leaves an adopted attempt alone' );
expect( true === $sweeper->sweep_order( open_attempt( 'tr_old', 3600 ) ) && 1 === $service->cancels, 'an unadopted stale attempt is still swept' );

// The order-status cleanup: an adopted attempt is Pro's to cancel.
$service = new CountingServiceForGuards();
$cleanup = new PaymentCleanup( $service );
$cleanup->maybe_cancel_abandoned_payment( 777, 'pending', 'cancelled', open_attempt( 'tr_adopted', 10 ) );
expect( 0 === $service->cancels, 'the cleanup leaves an adopted attempt alone' );
$cleanup->retry_cancel( 777, 'tr_adopted', 1 );
$GLOBALS['order'] = open_attempt( 'tr_adopted', 10 ); $GLOBALS['order']->status = 'cancelled';
$cleanup->retry_cancel( 777, 'tr_adopted', 1 );
expect( 0 === $service->cancels, 'the cleanup retry leaves an adopted attempt alone' );
$cleanup->maybe_cancel_abandoned_payment( 777, 'pending', 'cancelled', open_attempt( 'tr_old', 10 ) );
expect( 1 === $service->cancels, 'an unadopted attempt is still cancelled when the order leaves' );

// A tab still on the old panel: poll, cancel and start are refused with a reload message.
foreach ( array( 'mtfwc_poll_payment', 'mtfwc_cancel_payment', 'mtfwc_start_payment' ) as $action ) {
	$GLOBALS['order'] = open_attempt( 'tr_adopted', 10 );
	$_POST = array( 'order_id' => '777', 'order_token' => AjaxHandler::order_token( 777 ) );
	try { ( new AjaxHandler() )->$action(); expect( false, "$action should answer" ); } catch ( JsonResponseForGuards $r ) {
		expect( 409 === $r->status && false !== strpos( $r->data, 'Reload the page' ), "$action on an adopted attempt is refused with a reload message" );
	}
}
// The adopted reference kept on the order counts once the pointer moved on.
$GLOBALS['order'] = open_attempt( 'tr_newer', 10 ); $GLOBALS['order']->meta['_mtfwc_adopted_ref'] = 'tr_adopted';
$_POST = array( 'order_id' => '777', 'order_token' => AjaxHandler::order_token( 777 ) );
try { ( new AjaxHandler() )->mtfwc_poll_payment(); expect( false, 'poll should answer' ); } catch ( JsonResponseForGuards $r ) { expect( 409 === $r->status, 'the kept reference is honoured' ); }
// An unadopted attempt polls as before (here: idle after Mollie is unreachable is not reached; no attempt means idle).
$GLOBALS['order'] = new FakeOrderForGuards();
try { ( new AjaxHandler() )->mtfwc_poll_payment(); expect( false, 'poll should answer' ); } catch ( JsonResponseForGuards $r ) { expect( 200 === $r->status && 'idle' === $r->data['status'], 'an unadopted order polls as before' ); }

// The legacy webhook: an adopted delivery is acknowledged before any call to Mollie.
$GLOBALS['http'] = array(); $GLOBALS['log'] = array();
( new WebhookHandler() )->process( 'tr_adopted' );
expect( array() === $GLOBALS['http'], 'an adopted delivery makes no call to Mollie' );
expect( 1 === count( array_filter( $GLOBALS['log'], static function ( $m ) { return false !== strpos( $m, 'adopted' ); } ) ), 'the skip is logged' );
( new WebhookHandler() )->process( 'tr_other' );
expect( array( 'https://api.mollie.com/v2/payments/tr_other' ) === $GLOBALS['http'], 'any other delivery is fetched from Mollie as before' );
( new WebhookHandler() )->process( '' );
expect( 1 === count( $GLOBALS['http'] ), 'an empty delivery is acknowledged without a call' );

echo "legacy-adopted-guards ok\n";
