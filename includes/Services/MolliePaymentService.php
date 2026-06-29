<?php
namespace WCPOS\WooCommercePOS\MollieTerminal\Services;

use RuntimeException;
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
			if ( $order->is_paid() ) { return array( 'status' => 'already_paid' ); }
			$current = PaymentAttempt::current( $order );
			if ( $current && ! empty( $current['payment_id'] ) ) {
				$remote = $this->client->get_payment( $current['payment_id'] );
				$result = $this->reconciler->reconcile( $order, $remote, 'create_reuse' );
				$status = $result['payment_status'] ?? $result['status'] ?? '';
				if ( in_array( $status, array( 'open', 'pending', 'authorized', 'paid' ), true ) ) {
					return array_merge( $result, array( 'payment' => $remote, 'reused' => true ) );
				}
				if ( ! PaymentAttempt::is_final_unpaid( (string) $status ) ) {
					return array_merge( $result, array( 'reused' => true ) );
				}
			}
			$this->terminals->validate_terminal( $terminal_id );
			$amount = Money::to_mollie_value( $order->get_total(), $order->get_currency() );
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
			return array( 'status' => 'created', 'payment' => $payment );
		} );
	}

	public function poll_order( $order ): array {
		$current = PaymentAttempt::current( $order );
		if ( ! $current ) { return array( 'status' => 'idle' ); }
		$status = (string) ( $current['status'] ?? '' );
		if ( PaymentAttempt::is_non_final( $status ) ) {
			$payment = $this->client->get_payment( $current['payment_id'] );
			$result = $this->reconciler->reconcile( $order, $payment, 'poll' );
		} else {
			$result = array( 'status' => $status );
		}
		$created = strtotime( $current['created_at'] ?? '' );
		if ( $created && time() - $created > 60 && in_array( $result['status'] ?? '', array( 'open', 'pending' ), true ) ) {
			$result['message'] = __( 'Still waiting; refresh terminal status or cancel/retry.', 'mollie-terminal-for-woocommerce' );
		}
		return $result;
	}

	public function cancel_order_payment( $order ): array {
		return PaymentLock::with_lock( (int) $order->get_id(), 'cancel_payment', function () use ( $order ) {
			$current = PaymentAttempt::current( $order );
			if ( ! $current ) { return array( 'status' => 'idle' ); }
			$payment = $this->client->get_payment( $current['payment_id'] );
			if ( ! PaymentAttempt::is_non_final( (string) ( $payment['status'] ?? '' ) ) ) {
				return $this->reconciler->reconcile( $order, $payment, 'cancel' );
			}
			if ( isset( $payment['isCancelable'] ) && ! $payment['isCancelable'] ) {
				return array( 'status' => 'not_cancelable', 'message' => __( 'This Mollie payment cannot be canceled from WooCommerce.', 'mollie-terminal-for-woocommerce' ) );
			}
			try { $this->client->cancel_payment( $current['payment_id'] ); } catch ( RuntimeException $e ) { /* completion race: fetch and reconcile below */ }
			$payment = $this->client->get_payment( $current['payment_id'] );
			return $this->reconciler->reconcile( $order, $payment, 'cancel' );
		} );
	}
}
