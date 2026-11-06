<?php
// An attempt Pro adopted on upgrade is Pro's leg: the stale-payment sweep neither cancels nor
// completes it (the abandoned list included), the completion claim completes nothing for it, a tab
// still on the old panel is refused (and under Pro's panel no old start is accepted at all), and the
// legacy webhook acknowledges its delivery without calling Mollie. The order-status cleanup still
// cancels it when the order leaves the payable state: Pro voids its leg only on cancelled or failed.
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
function admin_url( $path = '' ) { return 'https://shop.example/wp-admin/' . $path; }
function wc_get_order( $id ) { return ( \WCPOS\WooCommercePOS\Payments\Contract\Order_Lock::$held && isset( $GLOBALS['fresh_order'] ) ) ? $GLOBALS['fresh_order'] : $GLOBALS['order']; }
function wcpos_pro_payment_id_for_action( $provider, $ref ) { return $GLOBALS['adopted_map'][ $ref ] ?? null; }
function wcpos_get_settings( $id, $key = null ) {
	// Read inside the start callback, after the AJAX ledger check: a row set here lands between the two.
	if ( isset( $GLOBALS['late_row'] ) ) { $GLOBALS['ledger_rows'][777][] = $GLOBALS['late_row']; unset( $GLOBALS['late_row'] ); }
	return array( 'gateways' => array( 'mollie_terminal_for_woocommerce' => array( 'enabled' => true ) ) );
}
function wp_remote_request( $url, $args ) { $GLOBALS['http'][] = $url; return new WP_Error( 'http_request_failed', 'offline' ); }
class SilentLoggerForGuards { public function log( $level, $message, $context = array() ) { $GLOBALS['log'][] = $message; } }
function wc_get_logger() { return new SilentLoggerForGuards(); }
class JsonResponseForGuards extends Error { public $data; public $status; public function __construct( $data, int $status ) { parent::__construct( 'json', $status ); $this->data = $data; $this->status = $status; } }
function wp_send_json_error( $data = null, $status_code = null ) { throw new JsonResponseForGuards( $data, (int) $status_code ); }
function wp_send_json_success( $data = null, $status_code = null ) { throw new JsonResponseForGuards( $data, 200 ); }

// The old panel's render (payment_fields) and Pro's tile create (Mollie_Server_Provider) load here too.
class WC_Payment_Gateway { public $id; public $method_title; public $method_description; public $supports = array(); public $title; public $description; public $form_fields = array(); public function init_settings() {} public function get_option( $key, $default = '' ) { return $default; } }
function is_checkout_pay_page() { return true; }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $text ) { return esc_html( $text ); }
function esc_html__( $text, $domain = null ) { return $text; }
function esc_attr__( $text, $domain = null ) { return $text; }
function wp_kses_post( $text ) { return $text; }
require_once __DIR__ . '/stubs/wcpos-pro-server.php';
require_once __DIR__ . '/stubs/ledger.php';
require_once __DIR__ . '/stubs/order-lock.php';
function wc_get_orders( $args ) { return ( $GLOBALS['order'] ?? null ) && ( $GLOBALS['order']->meta[ '_mtfwc_current_payment_id' ] ?? null ) === ( $args['meta_value'] ?? null ) ? array( $GLOBALS['order'] ) : array(); }
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
require_once __DIR__ . '/../../includes/Gateway.php';
require_once __DIR__ . '/../../includes/Server/Mollie_Server_Provider.php';

use WCPOS\WooCommercePOS\MollieTerminal\AjaxHandler;
use WCPOS\WooCommercePOS\MollieTerminal\Gateway;
use WCPOS\WooCommercePOS\MollieTerminal\Server\Mollie_Server_Provider;
use WCPOS\WooCommercePOS\MollieTerminal\Services\MollieApiClient;
use WCPOS\WooCommercePOS\MollieTerminal\Settings;
use WCPOS\WooCommercePOS\MollieTerminal\PaymentAttempt;
use WCPOS\WooCommercePOS\MollieTerminal\PaymentCleanup;
use WCPOS\WooCommercePOS\MollieTerminal\PaymentSweeper;
use WCPOS\WooCommercePOS\MollieTerminal\Services\MolliePaymentService;
use WCPOS\WooCommercePOS\MollieTerminal\WebhookHandler;
use WCPOS\WooCommercePOS\Payments\Contract\Order_Lock;

$wpdb = new FakeWpdb();
$GLOBALS['options'] = array( 'woocommerce_mollie_terminal_for_woocommerce_settings' => array( 'api_key' => 'live_k' ) );

class FakeOrderForGuards {
	public $meta = array(); public $paid = false; public $notes = array(); public $status = 'processing'; public $completed = 0;
	public function get_id() { return 777; }
	public function get_total() { return '10.00'; }
	public function get_currency() { return 'EUR'; }
	public function get_order_number() { return '777'; }
	public function get_checkout_order_received_url() { return 'https://shop.example/received/777'; }
	public $txn = '';
	public function get_transaction_id() { return $this->txn; }
	public function set_transaction_id( $id ) { $this->txn = $id; }
	public function payment_complete( $id = '' ) { $this->completed++; $this->paid = true; return true; }
	public function delete_meta_data( $key ) { unset( $this->meta[ $key ] ); }
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
class FreshOrderForGuards extends FakeOrderForGuards { public function get_id() { return 778; } }
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

// Pro owns tr_adopted while its row is live.
$GLOBALS['adopted_map'] = array( 'tr_adopted' => 'row-1' );
$GLOBALS['ledger_rows'] = array( 777 => array( array( 'id' => 'row-1', 'status' => 'pending' ) ) );

// The sweep: an adopted open attempt past the threshold is left alone; an unadopted one is swept.
$service = new CountingServiceForGuards();
$sweeper = new PaymentSweeper( $service );
expect( false === $sweeper->sweep_order( open_attempt( 'tr_adopted', 3600 ) ) && 0 === $service->cancels, 'the sweep leaves an adopted attempt alone' );
expect( true === $sweeper->sweep_order( open_attempt( 'tr_old', 3600 ) ) && 1 === $service->cancels, 'an unadopted stale attempt is still swept' );
// Once Pro's leg has ended without money, the old paths act on the payment again.
$GLOBALS['ledger_rows'][777][0]['status'] = 'voided';
expect( true === $sweeper->sweep_order( open_attempt( 'tr_adopted', 3600 ) ) && 2 === $service->cancels, 'an adopted attempt whose Pro leg ended is swept as before adoption' );
$GLOBALS['ledger_rows'][777][0]['status'] = 'pending';

// The order-status cleanup cancels an adopted attempt too when the order is paid another way: a
// terminal payment left open is a second charge waiting for a tap, and Pro voids only on
// cancelled or failed.
$service = new CountingServiceForGuards();
$cleanup = new PaymentCleanup( $service );
$cleanup->maybe_cancel_abandoned_payment( 777, 'pending', 'processing', open_attempt( 'tr_adopted', 10 ) );
expect( 1 === $service->cancels, 'the cleanup cancels an adopted attempt when the order is paid another way' );
// The payment that completed the order (Pro's capture) is not open: nothing to cancel, no note.
$paid_by_it = open_attempt( 'tr_adopted', 10 ); $paid_by_it->txn = 'tr_adopted';
$cleanup->maybe_cancel_abandoned_payment( 777, 'pending', 'processing', $paid_by_it );
expect( 1 === $service->cancels, 'the payment that paid the order is not cancelled' );
$GLOBALS['order'] = open_attempt( 'tr_adopted', 10 ); $GLOBALS['order']->status = 'processing';
$cleanup->retry_cancel( 777, 'tr_adopted', 1 );
expect( 2 === $service->cancels, 'the cleanup retry cancels it too' );

// The abandoned list: an interrupted set-aside can leave the adopted payment there; it leaves the
// list without a call to Mollie while the other entries are resolved as before.
$abandoned = new FakeOrderForGuards();
$abandoned->meta[ PaymentAttempt::META_ABANDONED_PAYMENT_IDS ] = array( 'tr_adopted', 'tr_other' );
$GLOBALS['order'] = $abandoned; $GLOBALS['http'] = array();
$results = ( new MolliePaymentService( new \WCPOS\WooCommercePOS\MollieTerminal\Services\MollieApiClient( 'live_k' ), new \WCPOS\WooCommercePOS\MollieTerminal\Settings() ) )->cancel_abandoned_payments( $abandoned );
expect( 'adopted' === $results['tr_adopted'] && 'error' === $results['tr_other'], 'the adopted entry is left to Pro, the other is resolved (here: Mollie offline)' );
expect( array( 'https://api.mollie.com/v2/payments/tr_other' ) === $GLOBALS['http'], 'Mollie is asked about the other entry only' );
expect( array( 'tr_other' ) === PaymentAttempt::abandoned( $abandoned ), 'the adopted entry is forgotten from the abandoned list' );

// The completion claim: a paid payment adopted while this request was asking Mollie is left to Pro.
$racing = open_attempt( 'tr_adopted', 10 );
$racing->completed = 0;
$GLOBALS['order'] = $racing;
$result = ( new \WCPOS\WooCommercePOS\MollieTerminal\PaymentReconciler( new \WCPOS\WooCommercePOS\MollieTerminal\Settings() ) )->reconcile( $racing, array( 'id' => 'tr_adopted', 'status' => 'paid', 'amount' => array( 'value' => '10.00', 'currency' => 'EUR' ) ), 'webhook' );
expect( array( 'status' => 'pending', 'completing' => true, 'retry_allowed' => false ) === $result && 0 === $racing->completed, 'the reconciler completes nothing for an adopted payment under its claim' );

// Under Pro's panel no old-panel start is accepted, adopted attempt or none; poll and cancel of an
// attempt Pro did not adopt go on.
$GLOBALS['order'] = new FakeOrderForGuards();
$_POST = array( 'order_id' => '777', 'order_token' => AjaxHandler::order_token( 777 ), 'terminal_id' => 'term_1' );
try { ( new AjaxHandler() )->mtfwc_start_payment(); expect( false, 'start should answer' ); } catch ( JsonResponseForGuards $r ) { expect( 409 === $r->status, 'under Pro\'s panel an old-panel start is refused even with nothing adopted' ); }
$GLOBALS['options']['woocommerce_mollie_terminal_for_woocommerce_settings']['qr_methods'] = array( 'ideal' );
$_POST = array( 'order_id' => '777', 'order_token' => AjaxHandler::order_token( 777 ), 'channel' => 'qr', 'qr_method' => 'ideal' );
$kept_rows = $GLOBALS['ledger_rows']; $GLOBALS['ledger_rows'] = array(); $GLOBALS['http'] = array();
try { ( new AjaxHandler() )->mtfwc_start_payment(); expect( false, 'start should answer' ); } catch ( JsonResponseForGuards $r ) { expect( 409 !== $r->status && array( 'https://api.mollie.com/v2/payments?include=details.qrCode' ) === $GLOBALS['http'], 'under the QR carve-out, with no ledger rows, the old panel starts as before (here it reaches Mollie)' ); }
expect( in_array( 777, Order_Lock::$locked, true ), 'the create runs under Free\'s order lock, the one Pro creates its payment under' );

// Under the QR carve-out, no old-panel start beside a live row in Free's ledger, of any method.
$till = 'This sale has a payment recorded at the till. Finish it at the till.';
$start_refused = static function ( string $message ) use ( $till ) {
	$GLOBALS['http'] = array();
	try { ( new AjaxHandler() )->mtfwc_start_payment(); expect( false, 'start should answer' ); } catch ( JsonResponseForGuards $r ) {
		expect( 409 === $r->status && $till === $r->data && array() === $GLOBALS['http'], $message );
	}
};
// Pro's tile leg, pending and never adopted (no attempt pointer, no adoption record).
$GLOBALS['order'] = new FakeOrderForGuards();
$GLOBALS['ledger_rows'] = array( 777 => array( array( 'id' => 'row-tile', 'status' => 'pending', 'method_id' => 'mollie_terminal_for_woocommerce', 'capture_mode' => 'server', 'provider_refs' => array( 'action' => 'tr_tile' ) ) ) );
$start_refused( 'a pending, unadopted Pro leg refuses an old-panel start' );
// Cash taken at the till for part of the total: the old panel would charge the full total again.
$GLOBALS['order']->status = 'pos-partial';
$GLOBALS['ledger_rows'] = array( 777 => array( array( 'id' => 'row-cash', 'status' => 'captured', 'method_id' => 'pos_cash', 'capture_mode' => 'manual' ) ) );
$start_refused( 'a captured cash row on a part-paid order refuses an old-panel start' );
// The old panel's own open attempt beside Pro's leg: start answers the refusal before asking Mollie
// to resume it; poll goes on, so the attempt still settles.
$GLOBALS['order'] = open_attempt( 'tr_mine', 10 );
$GLOBALS['ledger_rows'] = array( 777 => array( array( 'id' => 'row-tile', 'status' => 'pending', 'method_id' => 'mollie_terminal_for_woocommerce', 'capture_mode' => 'server' ) ) );
$start_refused( 'beside a live row a start is refused before any call to Mollie, even with an open attempt of its own' );
$_POST = array( 'order_id' => '777', 'order_token' => AjaxHandler::order_token( 777 ) );
try { ( new AjaxHandler() )->mtfwc_poll_payment(); expect( false, 'poll should answer' ); } catch ( JsonResponseForGuards $r ) { expect( 409 !== $r->status && array( 'https://api.mollie.com/v2/payments/tr_mine' ) === $GLOBALS['http'], 'poll of the old panel\'s own attempt goes on beside a live row' ); }
$_POST = array( 'order_id' => '777', 'order_token' => AjaxHandler::order_token( 777 ), 'channel' => 'qr', 'qr_method' => 'ideal' );
// A start resuming the old panel's own open attempt keeps working while no row is live.
$GLOBALS['ledger_rows'] = array(); $GLOBALS['http'] = array();
try { ( new AjaxHandler() )->mtfwc_start_payment(); expect( false, 'start should answer' ); } catch ( JsonResponseForGuards $r ) { expect( 409 !== $r->status && array( 'https://api.mollie.com/v2/payments/tr_mine' ) === $GLOBALS['http'], 'a start resumes the old panel\'s own attempt (here it asks Mollie about it)' ); }
// Its own completed attempt mints a webview row; the paid order answers already_paid, not the refusal.
$GLOBALS['order'] = new FakeOrderForGuards(); $GLOBALS['order']->paid = true;
$GLOBALS['ledger_rows'] = array( 777 => array( array( 'id' => 'row-webview', 'status' => 'captured', 'method_id' => 'mollie_terminal_for_woocommerce', 'capture_mode' => 'webview' ) ) );
try { ( new AjaxHandler() )->mtfwc_start_payment(); expect( false, 'start should answer' ); } catch ( JsonResponseForGuards $r ) { expect( 200 === $r->status && 'already_paid' === $r->data['status'], 'a paid order with its own webview row answers already_paid' ); }
// A row that lands after the AJAX check passed (a tile start between the two) still stops the create:
// start_attempt() reads the ledger again under the order lock and the panel gets the same 409.
$GLOBALS['order'] = new FakeOrderForGuards();
$GLOBALS['ledger_rows'] = array();
$GLOBALS['late_row'] = array( 'id' => 'row-late', 'status' => 'pending', 'method_id' => 'mollie_terminal_for_woocommerce', 'capture_mode' => 'server' );
$start_refused( 'a live row that appears after the AJAX check still stops the create' );
expect( ! isset( $GLOBALS['late_row'] ), 'the late row was written after the AJAX check' );
// A busy lock refuses the start before any call to Mollie.
$GLOBALS['order'] = new FakeOrderForGuards(); $GLOBALS['ledger_rows'] = array(); $GLOBALS['http'] = array();
Order_Lock::$refuse = array( 777 );
try { ( new AjaxHandler() )->mtfwc_start_payment(); expect( false, 'start should answer' ); } catch ( JsonResponseForGuards $r ) { expect( 409 === $r->status && 'busy' === $r->data && array() === $GLOBALS['http'], 'a busy order lock refuses the start' ); }
Order_Lock::$refuse = array();
// The check under the lock reads a fresh copy of the order, not the one the AJAX check read.
$GLOBALS['order'] = new FakeOrderForGuards(); $GLOBALS['fresh_order'] = new FreshOrderForGuards();
$GLOBALS['ledger_rows'] = array( 778 => array( array( 'id' => 'row-fresh', 'status' => 'pending', 'method_id' => 'mollie_terminal_for_woocommerce', 'capture_mode' => 'server' ) ) );
$start_refused( 'a live row seen only in the fresh copy of the order still stops the create' );
unset( $GLOBALS['fresh_order'] );
// The old panel itself: beside a live row it offers no Start, only the till's message; a panel
// resuming its own attempt keeps the Cancel for it (poll and cancel go on).
$GLOBALS['wp'] = (object) array( 'query_vars' => array( 'order-pay' => 777 ) );
$render = static function (): string { ob_start(); ( new Gateway() )->payment_fields(); return ob_get_clean(); };
$GLOBALS['order'] = new FakeOrderForGuards();
$GLOBALS['ledger_rows'] = array( 777 => array( array( 'id' => 'row-tile', 'status' => 'pending', 'method_id' => 'mollie_terminal_for_woocommerce', 'capture_mode' => 'server' ) ) );
$html = $render();
expect( false === strpos( $html, 'mtfwc-primary-action' ) && false !== strpos( $html, '<p class="mtfwc-till-notice">' . $till . '</p>' ), 'beside a live row the old panel offers no Start, only the till\'s message' );
$GLOBALS['order'] = open_attempt( 'tr_mine', 10 );
$html = $render();
expect( false !== strpos( $html, 'data-resume="1"' ) && false !== strpos( $html, 'data-mtfwc-mode="cancel"' ) && false === strpos( $html, 'mtfwc-till-notice' ), 'a panel resuming its own attempt beside a live row keeps its Cancel' );
// A paid order shows no till notice.
$GLOBALS['order'] = new FakeOrderForGuards(); $GLOBALS['order']->paid = true;
$GLOBALS['ledger_rows'] = array( 777 => array( array( 'id' => 'row-cap', 'status' => 'captured', 'method_id' => 'pos_cash', 'capture_mode' => 'manual' ) ) );
expect( false === strpos( $render(), 'mtfwc-till-notice' ), 'a paid order shows no till notice' );

// The mirror: Pro's tile start (create_reader_action) beside the old panel's open attempt is refused
// before Mollie is asked; a final attempt, an attempt Pro owns or no attempt lets it through.
class ReaderClientForGuards extends MollieApiClient {
	public $keys = array();
	public function __construct() {}
	public function create_payment( array $payload, array $include = array(), string $idempotency_key = '' ): array { $this->keys[] = $idempotency_key; return array( 'id' => 'tr_tile' ); }
}
$reader_client = new ReaderClientForGuards();
$provider = new Mollie_Server_Provider( new Settings( array( 'mode' => 'live' ) ), $reader_client );
$tile_row = array( 'id' => 'row-tile', 'order_id' => 777, 'amount' => '10.00', 'currency' => 'EUR' );
$GLOBALS['ledger_rows'] = array( 777 => array( array( 'id' => 'row-1', 'status' => 'pending' ) ) ); // Pro's leg for tr_adopted.
$attempt = static function ( string $payment_id, string $status ): FakeOrderForGuards {
	$order = open_attempt( $payment_id, 10 ); $order->meta[ PaymentAttempt::META_CURRENT_PAYMENT_STATUS ] = $status; return $order;
};
foreach ( array( 'open', PaymentAttempt::STATUS_PAID_UNVERIFIED ) as $status ) {
	$GLOBALS['order'] = $attempt( 'tr_mine', $status );
	$result = $provider->create_reader_action( $tile_row, 'term_A' );
	expect( is_wp_error( $result ) && 409 === $result->get_error_data()['status'] && 'An earlier payment for this sale is still open on the payment page. Finish or cancel it there first.' === $result->get_error_message() && array() === $reader_client->keys, "a tile start beside the old panel's $status attempt is refused before Mollie is asked" );
}
$tile_ok = array( 'ref' => 'tr_tile', 'expires_at' => null );
$GLOBALS['order'] = $attempt( 'tr_mine', 'canceled' );
expect( $tile_ok === $provider->create_reader_action( $tile_row, 'term_A' ), 'a final old-panel attempt does not refuse a tile start' );
$GLOBALS['order'] = $attempt( 'tr_adopted', 'open' );
expect( $tile_ok === $provider->create_reader_action( $tile_row, 'term_A' ), 'an attempt Pro owns does not refuse a tile start' );
$GLOBALS['order'] = new FakeOrderForGuards();
expect( $tile_ok === $provider->create_reader_action( $tile_row, 'term_A' ) && $tile_ok === $provider->create_reader_action( $tile_row, 'term_A' ), 'with no attempt the tile start, and a second create of the same row, go through' );
expect( array_fill( 0, 4, 'row-tile' ) === $reader_client->keys, 'every create that went through used the row id as its Idempotency-Key' );
$GLOBALS['ledger_rows'] = $kept_rows;
$GLOBALS['options']['woocommerce_mollie_terminal_for_woocommerce_settings']['qr_methods'] = array();

// A tab still on the old panel: poll, cancel and start are refused with a reload message.
foreach ( array( 'mtfwc_poll_payment', 'mtfwc_cancel_payment', 'mtfwc_start_payment' ) as $action ) {
	$GLOBALS['order'] = open_attempt( 'tr_adopted', 10 );
	$_POST = array( 'order_id' => '777', 'order_token' => AjaxHandler::order_token( 777 ) );
	try { ( new AjaxHandler() )->$action(); expect( false, "$action should answer" ); } catch ( JsonResponseForGuards $r ) {
		expect( 409 === $r->status && false !== strpos( $r->data, 'Reload the page' ), "$action on an adopted attempt is refused with a reload message" );
	}
}
// Once Pro's leg has ended, the old panel's actions work again (here: poll answers idle, no attempt).
$GLOBALS['ledger_rows'][777][0]['status'] = 'voided';
$GLOBALS['order'] = open_attempt( 'tr_adopted', 10 );
$GLOBALS['order']->meta = array(); // the attempt was finished by the old poll meanwhile
$_POST = array( 'order_id' => '777', 'order_token' => AjaxHandler::order_token( 777 ) );
try { ( new AjaxHandler() )->mtfwc_poll_payment(); expect( false, 'poll should answer' ); } catch ( JsonResponseForGuards $r ) { expect( 200 === $r->status, 'after Pro\'s leg ended the old panel is not refused' ); }
$GLOBALS['ledger_rows'][777][0]['status'] = 'pending';
// The adopted reference kept on the order counts once the pointer moved on.
$GLOBALS['order'] = open_attempt( 'tr_newer', 10 ); $GLOBALS['order']->meta['_mtfwc_adopted_ref'] = 'tr_adopted';
$_POST = array( 'order_id' => '777', 'order_token' => AjaxHandler::order_token( 777 ) );
try { ( new AjaxHandler() )->mtfwc_poll_payment(); expect( false, 'poll should answer' ); } catch ( JsonResponseForGuards $r ) { expect( 409 === $r->status, 'the kept reference is honoured' ); }
// An unadopted attempt polls as before (here: idle after Mollie is unreachable is not reached; no attempt means idle).
$GLOBALS['order'] = new FakeOrderForGuards();
try { ( new AjaxHandler() )->mtfwc_poll_payment(); expect( false, 'poll should answer' ); } catch ( JsonResponseForGuards $r ) { expect( 200 === $r->status && 'idle' === $r->data['status'], 'an unadopted order polls as before' ); }

// The legacy webhook: an adopted delivery is acknowledged before any call to Mollie.
$GLOBALS['http'] = array(); $GLOBALS['log'] = array();
$GLOBALS['order'] = open_attempt( 'tr_adopted', 10 );
( new WebhookHandler() )->process( 'tr_adopted' );
expect( array() === $GLOBALS['http'], 'an adopted delivery makes no call to Mollie' );
$GLOBALS['ledger_rows'][777][0]['status'] = 'voided';
( new WebhookHandler() )->process( 'tr_adopted' );
expect( array( 'https://api.mollie.com/v2/payments/tr_adopted' ) === $GLOBALS['http'], 'once Pro\'s leg ended a late delivery is processed as before adoption' );
$GLOBALS['ledger_rows'][777][0]['status'] = 'pending'; $GLOBALS['http'] = array();
expect( 1 === count( array_filter( $GLOBALS['log'], static function ( $m ) { return false !== strpos( $m, 'left to the POS' ); } ) ), 'the skip is logged' );
( new WebhookHandler() )->process( 'tr_other' );
expect( array( 'https://api.mollie.com/v2/payments/tr_other' ) === $GLOBALS['http'], 'any other delivery is fetched from Mollie as before' );
expect( 1 === count( array_filter( $GLOBALS['log'], static function ( $m ) { return false !== strpos( $m, 'left to the POS' ); } ) ), 'the skip is logged once' );
( new WebhookHandler() )->process( '' );
expect( 1 === count( $GLOBALS['http'] ), 'an empty delivery is acknowledged without a call' );

echo "legacy-adopted-guards ok\n";
