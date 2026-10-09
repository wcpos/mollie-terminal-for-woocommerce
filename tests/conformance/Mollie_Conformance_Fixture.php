<?php
/**
 * Extension-owned fixture for Pro's provider conformance suite: the real gateway, adapter and
 * services, with only Mollie's HTTP transport faked.
 *
 * @package WCPOS\WooCommercePOS\MollieTerminal\Tests\Conformance
 */

namespace WCPOS\WooCommercePOS\MollieTerminal\Tests\Conformance;

use WCPOS\WooCommercePOS\MollieTerminal\Gateway;
use WCPOS\WooCommercePOS\MollieTerminal\Settings;
use WCPOS\WooCommercePOSPro\API\V2\Payments_Webhook_Controller;
use WCPOS\WooCommercePOSPro\Payments\Server\Reader_Curation;
use WCPOS\WooCommercePOSPro\Payments\Server\Server_Providers;
use WCPOS\WooCommercePOSPro\Tests\Conformance\Conformance_Fixture;

require_once __DIR__ . '/Fake_Mollie_Transport.php';
require_once __DIR__ . '/Recording_Mollie_Provider.php';

/**
 * Capabilities claimed beyond the floor, and why:
 * - `cancel`, `cancel_final`, `cancel_requested_then_completed`: Mollie's cancel is a DELETE that
 *   succeeds at once while the payment is cancelable, and is refused once a terminal accepted it;
 *   the adapter then reports `requested` and polling decides.
 * - `webhook`, `webhook_money_only`: Mollie's delivery is an unsigned payment id; the adapter settles
 *   only money it has confirmed through the authenticated read, and a non-money outcome is left to
 *   polling. A delivery for a payment in the other mode is refused.
 * - `refund`, `partial_refund`: refunds are asynchronous (queued/pending/refunded), so `refund_pending`
 *   is a real state.
 * - `expiry`: Mollie expires an unpaid point-of-sale payment itself.
 * - `legacy_adoption`, `historical_webview_refund`: the webhook resolves an adopted action through
 *   Pro's record, and a refund falls back to the transaction reference.
 * Not claimed: `cancel_unsupported`, `manual_capture` (point-of-sale payments capture at once),
 * `prompt`, `test_live_isolation` (the adapter reads the configured key; a reference carries no mode).
 */
final class Mollie_Conformance_Fixture implements Conformance_Fixture {
	/**
	 * Scripted Mollie.
	 *
	 * @var Fake_Mollie_Transport
	 */
	public $transport;
	private $registry_property;
	private $old_registry;
	private $old_gateways;
	private $old_options;
	private $old_currency;
	private $calls = array();
	private $aliases = array();

	public function gateway_id(): string {
		return Settings::GATEWAY_ID;
	}

	public function install(): void {
		$this->old_options  = get_option( 'woocommerce_' . Settings::GATEWAY_ID . '_settings', array() );
		$this->old_currency = get_option( 'woocommerce_currency' );
		update_option( 'woocommerce_currency', 'EUR' );
		update_option( 'woocommerce_' . Settings::GATEWAY_ID . '_settings', array( 'mode' => 'live', 'api_key_source' => 'own', 'api_key' => 'live_conformance', 'default_terminal_id' => '' ) );
		$this->transport                    = new Fake_Mollie_Transport();
		Recording_Mollie_Provider::$fixture = $this;
		add_filter( 'pre_http_request', array( $this->transport, 'handle' ), 10, 3 );
		$this->registry_property = new \ReflectionProperty( Server_Providers::class, 'instance' );
		$this->registry_property->setAccessible( true );
		$this->old_registry = $this->registry_property->getValue();
		$this->registry_property->setValue( null, null );
		wcpos_pro_register_server_provider( Settings::GATEWAY_ID, Recording_Mollie_Provider::class );
		$this->old_gateways = WC()->payment_gateways;
		add_filter( 'woocommerce_payment_gateways', array( Gateway::class, 'register_gateway' ) );
		WC()->payment_gateways = new \WC_Payment_Gateways();
		Reader_Curation::forget( Settings::GATEWAY_ID );
		delete_option( 'wcpos_pro_readers_lkg_' . Settings::GATEWAY_ID );
	}

	public function uninstall(): void {
		remove_filter( 'pre_http_request', array( $this->transport, 'handle' ), 10 );
		Recording_Mollie_Provider::$fixture = null;
		Reader_Curation::forget( Settings::GATEWAY_ID );
		delete_option( 'wcpos_pro_readers_lkg_' . Settings::GATEWAY_ID );
		$this->registry_property->setValue( null, $this->old_registry );
		WC()->payment_gateways = $this->old_gateways;
		update_option( 'woocommerce_' . Settings::GATEWAY_ID . '_settings', $this->old_options );
		update_option( 'woocommerce_currency', $this->old_currency );
	}

	public function supports( string $capability ): bool {
		return in_array( $capability, array( 'cancel', 'cancel_final', 'cancel_requested_then_completed', 'webhook', 'webhook_money_only', 'refund', 'partial_refund', 'expiry', 'legacy_adoption', 'historical_webview_refund' ), true );
	}

	public function script( string $scenario ): void {
		// Scenario => states successive fetches observe, refund answer, cancelable.
		$scripts = array(
			'create_ok'                       => array( array( 'open' ) ),
			'create_indeterminate'            => array( array( 'open' ) ),
			'webhook_replay'                  => array( array( 'open' ) ),
			'webhook_out_of_order'            => array( array( 'open' ) ),
			'pending_then_completed'          => array( array( 'pending', 'paid' ) ),
			'declined'                        => array( array( 'failed' ) ),
			'cancel_requested_then_cancelled' => array( array( 'canceled' ), 'refunded', false ),
			'cancel_requested_then_completed' => array( array( 'paid' ), 'refunded', false ),
			'cancel_final'                    => array( array( 'open' ) ),
			'amount_mismatch'                 => array( array( 'short' ) ),
			'currency_mismatch'               => array( array( 'usd' ) ),
			'expired'                         => array( array( 'expired' ) ),
			'refund_ok'                       => array( array( 'paid' ), 'refunded' ),
			'refund_pending'                  => array( array( 'paid' ), 'pending' ),
			'refund_failed'                   => array( array( 'paid' ), 'failed' ),
		);
		if ( ! isset( $scripts[ $scenario ] ) ) {
			throw new \OutOfBoundsException( 'Unknown conformance scenario: ' . $scenario );
		}
		$this->transport->script( $scenario, ...$scripts[ $scenario ] );
	}

	public function webhook_request( string $event ): \WP_REST_Request {
		$tampered = 'tampered' === $event;
		$event    = $tampered ? 'completed' : $event;
		$states   = array( 'completed' => 'paid', 'failed' => 'failed', 'cancelled' => 'canceled' );
		if ( ! isset( $states[ $event ] ) ) {
			throw new \OutOfBoundsException( 'Unknown webhook event: ' . $event );
		}
		$id = (string) $this->transport->current;
		$this->transport->observe( $id, $states[ $event ] );
		if ( $tampered ) {
			// Mollie signs nothing: a delivery is a payment id, and the authenticated read is the
			// evidence. A payment of the other mode is not this store's.
			$this->transport->tamper( $id );
		}
		$request = new \WP_REST_Request( 'POST', Payments_Webhook_Controller::ROUTE );
		$request->set_query_params( array( 'provider' => 'mollie' ) );
		$request->set_header( 'Content-Type', 'application/x-www-form-urlencoded' );
		$request->set_body_params( array( 'id' => $id ) );
		$request->set_body( http_build_query( array( 'id' => $id ) ) );
		return $request;
	}

	/** One stable alias per Mollie payment. */
	public function alias( string $ref ): string {
		if ( ! isset( $this->aliases[ $ref ] ) ) {
			$this->aliases[ $ref ] = 'action_' . ( count( $this->aliases ) + 1 );
		}
		return $this->aliases[ $ref ];
	}

	/** Append an adapter operation to the transcript. */
	public function record( string $op, string $ref, string $details ): void {
		$this->calls[] = array( 'op' => $op, 'request' => 'action=' . $this->alias( $ref ) . ' ' . $details );
	}

	public function transcript(): array {
		return $this->calls;
	}

	public function reset_transcript(): void {
		$this->calls   = array();
		$this->aliases = array();
	}

	public function transcript_dir(): ?string {
		return __DIR__ . '/transcripts';
	}
}
