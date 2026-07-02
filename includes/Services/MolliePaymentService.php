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
			if ( isset( $payment['isCancelable'] ) && ! $payment['isCancelable'] ) {
				Logger::log( 'Mollie terminal payment is not cancelable.', array( 'order_id' => (int) $order->get_id(), 'payment_id' => $current['payment_id'] ?? '' ), 'warning' );
				return array( 'status' => 'not_cancelable', 'message' => __( 'This Mollie payment cannot be canceled from WooCommerce.', 'mollie-terminal-for-woocommerce' ) );
			}
			try { $this->client->cancel_payment( $current['payment_id'] ); } catch ( RuntimeException $e ) { /* completion race: fetch and reconcile below */ }
			$payment = $this->client->get_payment( $current['payment_id'] );
			Logger::log( 'Mollie terminal cancel request completed.', array( 'order_id' => (int) $order->get_id(), 'payment_id' => $current['payment_id'] ?? '', 'status' => $payment['status'] ?? '' ), 'success' );
			return $this->reconciler->reconcile( $order, $payment, 'cancel' );
		} );
	}
}
