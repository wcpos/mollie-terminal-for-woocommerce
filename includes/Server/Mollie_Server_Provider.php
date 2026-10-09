<?php
namespace WCPOS\WooCommercePOS\MollieTerminal\Server;

use InvalidArgumentException;
use RuntimeException;
use WCPOS\WooCommercePOS\MollieTerminal\Logger;
use WCPOS\WooCommercePOS\MollieTerminal\RefundReconciler;
use WCPOS\WooCommercePOS\MollieTerminal\Settings;
use WCPOS\WooCommercePOS\MollieTerminal\Services\MollieApiClient;
use WCPOS\WooCommercePOS\MollieTerminal\Services\MollieNotFoundException;
use WCPOS\WooCommercePOS\MollieTerminal\Services\MollieRefundPostUnansweredException;
use WCPOS\WooCommercePOS\MollieTerminal\Services\MollieUnansweredException;
use WCPOS\WooCommercePOS\MollieTerminal\Services\TerminalService;
use WCPOS\WooCommercePOS\MollieTerminal\Utils\Money;

/** Mollie transport and normalization; Free owns the payment ledger. */
class Mollie_Server_Provider extends \WCPOS\WooCommercePOSPro\Payments\Server\Abstract_Provider_Adapter {
	private $settings;
	private $client;
	private $terminals;

	public function __construct( ?Settings $settings = null, ?MollieApiClient $client = null, ?TerminalService $terminals = null ) {
		$this->settings = $settings ?: new Settings();
		$this->client = $client ?: new MollieApiClient( $this->settings->api_key() );
		$this->terminals = $terminals ?: new TerminalService( $this->client, $this->settings );
	}

	public function provider(): string { return 'mollie'; }

	public function describe( \WC_Payment_Gateway $gateway ): array {
		// Mollie's confirmed amount includes reader tips; Free records the excess as a fee.
		return array( 'capabilities' => array( 'tips' => 'on_reader', 'refunds' => array( 'via' => 'provider', 'partial' => true ), 'void' => true ), 'provider_data' => array( 'mode' => $this->settings->mode() ) );
	}

	public function list_readers() {
		try {
			$readers = array();
			foreach ( $this->terminals->list_terminals() as $terminal ) {
				if ( 'active' !== ( $terminal['status'] ?? '' ) || ( isset( $terminal['mode'] ) && $terminal['mode'] !== $this->settings->mode() ) ) { continue; }
				$id = (string) ( $terminal['id'] ?? '' );
				if ( '' === $id ) { continue; }
				$label = trim( (string) ( $terminal['description'] ?? '' ) );
				if ( '' === $label ) { $label = trim( ( $terminal['brand'] ?? '' ) . ' ' . ( $terminal['model'] ?? '' ) ); }
				// Mollie exposes activation, not connectivity.
				$readers[] = array( 'id' => $id, 'label' => '' === $label ? $id : $label, 'status' => 'online' );
			}
			return $readers;
		} catch ( RuntimeException | InvalidArgumentException $e ) {
			return self::provider_error( $e->getMessage() );
		}
	}

	public function create_reader_action( array $row, string $reader_id ) {
		try {
			try {
				$amount = Money::to_mollie_value( $row['amount'], $row['currency'] );
			} catch ( InvalidArgumentException $e ) {
				if ( 'EUR' !== strtoupper( $row['currency'] ) ) { return self::provider_error( $e->getMessage(), 'currency_unsupported', 400 ); }
				throw $e;
			}
			$order = wc_get_order( (int) $row['order_id'] );
			if ( ! $order ) { return new \WP_Error( 'wcpos_order_not_found', __( 'Order not found.', 'mollie-terminal-for-woocommerce' ), array( 'status' => 404 ) ); }
			$payload = array(
				'amount' => array( 'currency' => $row['currency'], 'value' => $amount ),
				'description' => sprintf( 'Order #%s', $order->get_order_number() ),
				'method' => 'pointofsale',
				'terminalId' => $reader_id,
				'redirectUrl' => $order->get_checkout_order_received_url(),
				'webhookUrl' => self::webhook_url(),
				'metadata' => array( 'wcpos_payment_id' => $row['id'], 'order_id' => (string) $order->get_id(), 'terminal_id' => $reader_id ),
			);
			$payment = $this->client->create_payment( $payload, array(), $row['id'] );
			return array( 'ref' => $payment['id'], 'expires_at' => $payment['expiresAt'] ?? null );
		} catch ( MollieUnansweredException $e ) {
			// The payment may exist: Free keeps the row pending, and the replay carries the same
			// Idempotency-Key (the row id), so Mollie hands back the payment the lost response made.
			return $this->indeterminate( 'mollie_unanswered', $e->getMessage() );
		} catch ( RuntimeException | InvalidArgumentException $e ) {
			return self::provider_error( $e->getMessage() );
		}
	}

	public function fetch( string $ref ) {
		try {
			return self::normalize( $this->client->get_payment( $ref ) );
		} catch ( MollieUnansweredException $e ) {
			return $this->indeterminate( 'mollie_unanswered', $e->getMessage() ); // Nothing observed; the next poll asks again.
		} catch ( RuntimeException | InvalidArgumentException $e ) {
			return self::provider_error( $e->getMessage() );
		}
	}

	/** Pure projection of Mollie's observation, not a ledger transition. */
	public static function normalize( array $payment ): array {
		$statuses = array( 'open' => 'pending', 'pending' => 'in_progress', 'authorized' => 'in_progress', 'paid' => 'completed', 'canceled' => 'cancelled', 'expired' => 'expired', 'failed' => 'failed' );
		$status = $payment['status'] ?? '';
		$result = array(
			'status' => $statuses[ $status ] ?? 'failed',
			'amount' => $payment['amount']['value'] ?? null,
			'currency' => $payment['amount']['currency'] ?? null,
			// `transaction_id` is what Free copies into the order's transaction id on capture, as the
			// old panel did, so refunds and "Payment via" read the same Mollie payment id.
			'provider_refs' => array( 'mollie_payment' => $payment['id'], 'transaction_id' => $payment['id'], 'mollie_mode' => $payment['mode'] ?? null ),
			'receipt' => self::receipt( $payment ),
		);
		if ( isset( $payment['details']['terminalId'] ) ) { $result['provider_refs']['reader'] = $payment['details']['terminalId']; }
		if ( 'failed' === $result['status'] ) {
			$result['failure_reason'] = 'failed' === $status ? ( $payment['statusReason']['code'] ?? $payment['details']['failureReason'] ?? 'provider_error' ) : 'provider_error';
		}
		return $result;
	}

	private static function receipt( array $payment ): array {
		$details = $payment['details'] ?? array();
		return array_filter( array(
			'card_label' => $details['cardLabel'] ?? null,
			'card_last4' => $details['cardNumber'] ?? null,
			'card_country' => $details['cardCountryCode'] ?? null,
			'card_audience' => $details['cardAudience'] ?? null,
			'card_funding' => $details['cardFunding'] ?? null,
			'auth_code' => $details['receipt']['authorizationCode'] ?? null,
			'read_method' => $details['receipt']['cardReadMethod'] ?? null,
			'verification' => $details['receipt']['cardVerificationMethod'] ?? null,
			'mollie_payment' => $payment['id'] ?? null,
		), static function ( $value ) { return is_string( $value ) && '' !== $value; } );
	}

	public function cancel( string $ref ) {
		try {
			$payment = $this->client->get_payment( $ref );
			$status = $payment['status'] ?? '';
			if ( in_array( $status, array( 'paid', 'authorized' ), true ) ) { return 'requested'; }
			if ( in_array( $status, array( 'canceled', 'expired', 'failed' ), true ) ) { return 'final'; }
			if ( isset( $payment['isCancelable'] ) && ! $payment['isCancelable'] ) { return 'requested'; }
			try {
				$this->client->cancel_payment( $ref );
			} catch ( RuntimeException | InvalidArgumentException $e ) { /* Completion race: only the re-read can confirm no capture. */ }
			$payment = $this->client->get_payment( $ref );
			return in_array( $payment['status'] ?? '', array( 'canceled', 'expired', 'failed' ), true ) ? 'final' : 'requested';
		} catch ( RuntimeException | InvalidArgumentException $e ) {
			return self::provider_error( $e->getMessage() );
		}
	}

	public function refund( array $row, int $refund_id, string $amount ) {
		try {
			$refund = wc_get_order( $refund_id );
			// A historical webview row (refunded by its transaction reference) names no order; the
			// refund record does.
			$order = wc_get_order( (int) ( $row['order_id'] ?? ( $refund ? $refund->get_parent_id() : 0 ) ) );
			if ( ! $order || ! $refund ) { return new \WP_Error( 'wcpos_refund_not_found', __( 'Order or refund not found.', 'mollie-terminal-for-woocommerce' ), array( 'status' => 404 ) ); }
			// The leg's own action, or, for a historical webview row, the Mollie payment id Free kept as
			// the transaction reference.
			$payment_id = (string) ( $row['provider_refs']['action'] ?? $row['provider_refs']['transaction_id'] ?? '' );
			if ( '' === $payment_id ) { return self::provider_error( __( 'No Mollie payment found for refund.', 'mollie-terminal-for-woocommerce' ), 'missing_payment_ref' ); }
			$result = ( new RefundReconciler( $this->client ) )->refund( $order, $refund, $amount, (string) $refund->get_reason(), $payment_id );
			$statuses = array( 'refunded' => 'succeeded', 'queued' => 'pending', 'pending' => 'pending', 'processing' => 'pending', 'failed' => 'failed', 'canceled' => 'failed' );
			return array( 'status' => $statuses[ $result['mollie_status'] ] ?? 'pending', 'provider_ref' => $result['refund_id'] ?: null );
		} catch ( MollieRefundPostUnansweredException $e ) {
			// The POST itself went unanswered: the refund may exist. An error here would make
			// WooCommerce delete the refund record, and a later refund would be a new record Mollie's
			// metadata cannot be matched to: a second refund. So the record stands as pending, the
			// order says so, and the reconciler's own ask finds the refund Mollie made by its attempt
			// id (the Idempotency-Key) or makes it under that same id.
			Logger::log( 'Mollie did not answer a refund POST; the refund record stays pending and is checked again.', array( 'refund_id' => $refund_id, 'message' => $e->getMessage() ), 'warning' );
			RefundReconciler::unanswered_post( $order, $refund_id, $payment_id, $amount );
			return array( 'status' => 'pending', 'provider_ref' => null );
		} catch ( RuntimeException | InvalidArgumentException $e ) {
			// A read Mollie did not answer, or a refusal: nothing was created, and the merchant sees it.
			return self::provider_error( $e->getMessage() );
		}
	}

	public function verify_webhook( \WP_REST_Request $request ) {
		$id = (string) $request->get_param( 'id' );
		if ( ! preg_match( '/^tr_[A-Za-z0-9]+$/', $id ) ) { return new \WP_Error( 'mollie_webhook_invalid_id', __( 'Invalid Mollie payment ID.', 'mollie-terminal-for-woocommerce' ), array( 'status' => 400 ) ); }
		// An attempt Pro adopted from the old panel carries no ledger id in its metadata; Pro's
		// adoption record names its row. A local read, before any call to Mollie.
		$adopted = function_exists( 'wcpos_pro_payment_id_for_action' ) ? wcpos_pro_payment_id_for_action( $this->provider(), $id ) : null;
		try {
			// The authenticated fetch, not the posted body, is the payment evidence.
			$payment = $this->client->get_payment( $id );
		} catch ( MollieNotFoundException $e ) {
			// A forged or foreign id: this key sees no such payment, and nothing is settled.
			return new \WP_Error( 'mollie_webhook_unknown_payment', __( 'Unknown POS payment.', 'mollie-terminal-for-woocommerce' ), array( 'status' => 404 ) );
		} catch ( RuntimeException | InvalidArgumentException $e ) {
			return self::provider_error( $e->getMessage() );
		}
		if ( ( $payment['mode'] ?? null ) !== $this->settings->mode() ) { return new \WP_Error( 'mollie_webhook_mode_mismatch', __( 'Mollie payment mode mismatch.', 'mollie-terminal-for-woocommerce' ), array( 'status' => 403 ) ); }
		$payment_id = null !== $adopted ? $adopted : ( $payment['metadata']['wcpos_payment_id'] ?? '' );
		if ( ! is_string( $payment_id ) || ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $payment_id ) ) { return new \WP_Error( 'mollie_webhook_unknown_payment', __( 'Unknown POS payment.', 'mollie-terminal-for-woocommerce' ), array( 'status' => 404 ) ); }
		return array( 'payment_id' => strtolower( $payment_id ), 'patch' => self::webhook_patch( $payment ) );
	}

	/** Non-money outcomes belong to polling, which knows whether a void was requested. */
	public static function webhook_patch( array $payment ): array {
		$patch = array( 'event_id' => $payment['id'] . ':' . $payment['status'] );
		if ( 'paid' === $payment['status'] ) {
			// Settlement replaces the row's refs with the patch's, so a capture carries the complete
			// set: the action and reader Pro keeps, and the transaction id Free copies onto the
			// order, which a leg settled by webhook before any poll would otherwise lack.
			$patch += array( 'status' => 'captured', 'amount' => $payment['amount']['value'] ?? null, 'currency' => $payment['amount']['currency'] ?? null, 'provider_refs' => array( 'action' => $payment['id'] ) + self::normalize( $payment )['provider_refs'], 'receipt' => self::receipt( $payment ) );
		}
		return $patch;
	}

	public static function webhook_url(): string { return add_query_arg( 'provider', 'mollie', rest_url( 'wcpos/v2/payments/webhook' ) ); }

	private static function provider_error( string $message, string $code = 'mollie_api_error', int $status = 502 ): \WP_Error {
		return new \WP_Error( 'wcpos_provider_error', $message, array( 'status' => $status, 'detail' => array( 'code' => $code, 'message' => $message ) ) );
	}
}
