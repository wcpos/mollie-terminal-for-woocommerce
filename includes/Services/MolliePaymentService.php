<?php
namespace WCPOS\WooCommercePOS\MollieTerminal\Services;

use RuntimeException;
use WCPOS\WooCommercePOS\MollieTerminal\Logger;
use WCPOS\WooCommercePOS\MollieTerminal\PaymentAttempt;
use WCPOS\WooCommercePOS\MollieTerminal\PaymentLock;
use WCPOS\WooCommercePOS\MollieTerminal\PaymentReconciler;
use WCPOS\WooCommercePOS\MollieTerminal\Settings;
use WCPOS\WooCommercePOS\MollieTerminal\Utils\Money;

class MolliePaymentService {
	private $client;
	private $settings;
	private $terminals;
	private $reconciler;

	public function __construct( MollieApiClient $client, Settings $settings, ?TerminalService $terminals = null, ?PaymentReconciler $reconciler = null ) {
		$this->client = $client;
		$this->settings = $settings;
		$this->terminals = $terminals ?: new TerminalService( $client, $settings );
		$this->reconciler = $reconciler ?: new PaymentReconciler( $settings );
	}

	public function start_payment_for_order( $order, string $terminal_id = '' ): array {
		$terminal_id = $terminal_id ?: $this->settings->default_terminal_id();
		return PaymentLock::with_lock( (int) $order->get_id(), 'create_payment', function () use ( $order, $terminal_id ) {
			if ( $order->is_paid() ) {
				Logger::log( 'Mollie terminal payment start skipped because order is already paid.', array( 'order_id' => (int) $order->get_id() ), 'info' );
				return array( 'status' => 'already_paid' );
			}
			$current = PaymentAttempt::current( $order );
			if ( $current && ! empty( $current['payment_id'] ) ) {
				$remote = $this->client->get_payment( $current['payment_id'] );
				$result = $this->reconciler->reconcile( $order, $remote, 'create_reuse' );
				$status = $result['payment_status'] ?? $result['status'] ?? '';
				if ( in_array( $status, array( 'open', 'pending', 'authorized', 'paid' ), true ) ) {
					Logger::log( 'Reusing active Mollie terminal payment.', array( 'order_id' => (int) $order->get_id(), 'payment_id' => $current['payment_id'], 'status' => $status ), 'info' );
					return array_merge( $result, array( 'payment' => $remote, 'reused' => true ) );
				}
				if ( ! PaymentAttempt::is_final_unpaid( (string) $status ) ) {
					Logger::log( 'Reusing non-final Mollie terminal payment state.', array( 'order_id' => (int) $order->get_id(), 'payment_id' => $current['payment_id'], 'status' => $status ), 'info' );
					return array_merge( $result, array( 'reused' => true ) );
				}
			}
			$this->terminals->validate_terminal( $terminal_id );
			$amount = Money::to_mollie_value( $order->get_total(), $order->get_currency() );
			Logger::log( 'Creating Mollie terminal payment.', array( 'order_id' => (int) $order->get_id(), 'terminal_id' => $terminal_id, 'amount' => $amount, 'currency' => $order->get_currency() ), 'info' );
			$payload = array(
				'amount' => array( 'currency' => $order->get_currency(), 'value' => $amount ),
				'description' => sprintf( 'Order #%s', $order->get_order_number() ),
				'method' => 'pointofsale',
				'terminalId' => $terminal_id,
				'redirectUrl' => $order->get_checkout_order_received_url(),
				'webhookUrl' => $this->settings->webhook_url(),
				'metadata' => array( 'order_id' => (string) $order->get_id(), 'terminal_id' => $terminal_id ),
			);
			$payment = $this->client->create_payment( $payload );
			PaymentAttempt::record_new( $order, $payment, $terminal_id, $this->settings->mode() );
			Logger::log( 'Mollie terminal payment created.', array( 'order_id' => (int) $order->get_id(), 'payment_id' => PaymentAttempt::payment_id( $payment ), 'status' => PaymentAttempt::payment_status( $payment ), 'terminal_id' => $terminal_id ), 'success' );
			return array( 'status' => 'created', 'payment' => $payment );
		} );
	}

	public function poll_order( $order ): array {
		$current = PaymentAttempt::current( $order );
		if ( ! $current ) {
			Logger::log( 'Mollie terminal poll skipped because no payment attempt exists.', array( 'order_id' => (int) $order->get_id() ), 'info' );
			return array( 'status' => 'idle' );
		}
		$status = (string) ( $current['status'] ?? '' );
		if ( PaymentAttempt::is_non_final( $status ) ) {
			Logger::log( 'Polling Mollie terminal payment.', array( 'order_id' => (int) $order->get_id(), 'payment_id' => $current['payment_id'] ?? '' ), 'info' );
			$payment = $this->client->get_payment( $current['payment_id'] );
			$result = $this->reconciler->reconcile( $order, $payment, 'poll' );
		} else {
			$result = array( 'status' => $status );
		}
		Logger::log( 'Mollie terminal poll completed.', array( 'order_id' => (int) $order->get_id(), 'status' => $result['status'] ?? '' ), 'info' );
		$created = strtotime( $current['created_at'] ?? '' );
		if ( $created && time() - $created > 60 && in_array( $result['status'] ?? '', array( 'open', 'pending' ), true ) ) {
			$result['message'] = __( 'Still waiting; refresh terminal status or cancel/retry.', 'mollie-terminal-for-woocommerce' );
		}
		return $result;
	}

	public function cancel_order_payment( $order ): array {
		return PaymentLock::with_lock( (int) $order->get_id(), 'cancel_payment', function () use ( $order ) {
			$current = PaymentAttempt::current( $order );
			if ( ! $current ) {
				Logger::log( 'Mollie terminal cancel skipped because no payment attempt exists.', array( 'order_id' => (int) $order->get_id() ), 'info' );
				return array( 'status' => 'idle' );
			}
			Logger::log( 'Canceling Mollie terminal payment.', array( 'order_id' => (int) $order->get_id(), 'payment_id' => $current['payment_id'] ?? '' ), 'info' );
			$payment = $this->client->get_payment( $current['payment_id'] );
			if ( ! PaymentAttempt::is_non_final( (string) ( $payment['status'] ?? '' ) ) ) {
				Logger::log( 'Mollie terminal cancel reconciled final payment state.', array( 'order_id' => (int) $order->get_id(), 'status' => $payment['status'] ?? '' ), 'info' );
				return $this->reconciler->reconcile( $order, $payment, 'cancel' );
			}
			// The payment is still open. Ask Mollie to cancel it when it allows
			// that (a payment already dispatched to and accepted by a terminal
			// reports isCancelable = false).
			$cancelable = ! isset( $payment['isCancelable'] ) || $payment['isCancelable'];
			if ( $cancelable ) {
				try { $this->client->cancel_payment( $current['payment_id'] ); } catch ( RuntimeException $e ) { /* completion race or transient failure: re-fetch below */ }
				$payment = $this->client->get_payment( $current['payment_id'] );
			}
			if ( ! PaymentAttempt::is_non_final( (string) ( $payment['status'] ?? '' ) ) ) {
				Logger::log( 'Mollie terminal cancel request completed.', array( 'order_id' => (int) $order->get_id(), 'payment_id' => $current['payment_id'] ?? '', 'status' => $payment['status'] ?? '' ), 'success' );
				return $this->reconciler->reconcile( $order, $payment, 'cancel' );
			}
			// Mollie will not (or cannot yet) cancel — most often an unresponsive
			// or powered-off terminal holding the payment open. Detach the attempt
			// locally so the cashier regains control and can start a fresh payment
			// or choose another method; the webhook and the stale-payment sweep
			// reconcile the lingering Mollie payment.
			PaymentAttempt::abandon_current( $order );
			$order->add_order_note( __( 'Mollie Terminal: payment could not be canceled (terminal unresponsive); attempt abandoned locally and left for automatic cleanup.', 'mollie-terminal-for-woocommerce' ) );
			$order->save();
			Logger::log( 'Mollie terminal payment abandoned locally; still open at Mollie.', array( 'order_id' => (int) $order->get_id(), 'payment_id' => $current['payment_id'] ?? '', 'status' => $payment['status'] ?? '' ), 'warning' );
			return array( 'status' => 'abandoned', 'message' => __( 'The terminal did not respond, so the payment was set aside. Start a new payment or choose another method.', 'mollie-terminal-for-woocommerce' ) );
		} );
	}

	/**
	 * Resolve payments that were detached from the order while still open at Mollie.
	 *
	 * cancel_order_payment() clears the current-attempt pointer so the cashier
	 * regains control, but the payment can still be open on the Mollie side. Those
	 * IDs are parked in order meta and retried here by the stale-payment sweep:
	 * cancel what Mollie will cancel, and complete the order when the terminal
	 * turns out to have approved the payment after all. Returns a
	 * payment_id => outcome map. Uses its own lock so it can run alongside (not
	 * inside) cancel_order_payment.
	 */
	public function cancel_abandoned_payments( $order ): array {
		$payment_ids = PaymentAttempt::abandoned( $order );
		if ( empty( $payment_ids ) ) { return array(); }
		return PaymentLock::with_lock( (int) $order->get_id(), 'cancel_abandoned', function () use ( $order, $payment_ids ) {
			$results = array();
			foreach ( $payment_ids as $payment_id ) {
				try {
					$results[ $payment_id ] = $this->resolve_abandoned_payment( $order, $payment_id );
				} catch ( RuntimeException $e ) {
					Logger::log( 'Could not resolve abandoned Mollie terminal payment: ' . $e->getMessage(), array( 'order_id' => (int) $order->get_id(), 'payment_id' => $payment_id ), 'error' );
					$results[ $payment_id ] = 'error';
				}
			}
			return $results;
		} );
	}

	private function resolve_abandoned_payment( $order, string $payment_id ): string {
		$payment = $this->client->get_payment( $payment_id );
		$status = PaymentAttempt::payment_status( $payment );
		if ( ! PaymentAttempt::is_final( $status ) ) {
			// Still holding the terminal hostage: only Mollie can tell us whether it
			// has given up on it yet.
			if ( isset( $payment['isCancelable'] ) && ! $payment['isCancelable'] ) {
				Logger::log( 'Abandoned Mollie terminal payment is still open and not cancelable; retrying on the next sweep.', array( 'order_id' => (int) $order->get_id(), 'payment_id' => $payment_id, 'status' => $status ), 'warning' );
				return 'still_open';
			}
			try { $this->client->cancel_payment( $payment_id ); } catch ( RuntimeException $e ) { /* completion race or transient failure: re-fetch below */ }
			$payment = $this->client->get_payment( $payment_id );
			$status = PaymentAttempt::payment_status( $payment );
		}
		if ( ! PaymentAttempt::is_final( $status ) ) { return 'still_open'; }
		// reconcile() completes the order when the payment turns out paid, and
		// drops the ID from the abandoned list now that it is final.
		$this->reconciler->reconcile( $order, $payment, 'abandoned_sweep' );
		Logger::log( 'Abandoned Mollie terminal payment resolved.', array( 'order_id' => (int) $order->get_id(), 'payment_id' => $payment_id, 'status' => $status ), 'success' );
		return $status;
	}
}
