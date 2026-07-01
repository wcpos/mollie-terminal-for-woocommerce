<?php
namespace WCPOS\WooCommercePOS\MollieTerminal;

use Exception;
use WCPOS\WooCommercePOS\MollieTerminal\Services\MollieApiClient;
use WCPOS\WooCommercePOS\MollieTerminal\Services\MolliePaymentService;
use WCPOS\WooCommercePOS\MollieTerminal\Services\TerminalService;

class AjaxHandler {
	public function __construct() {
		if ( ! function_exists( 'add_action' ) || ! wp_doing_ajax() ) { return; }
		foreach ( array( 'mtfwc_start_payment', 'mtfwc_poll_payment', 'mtfwc_cancel_payment', 'mtfwc_list_terminals' ) as $action ) {
			add_action( 'wp_ajax_' . $action, array( $this, $action ) );
			add_action( 'wp_ajax_nopriv_' . $action, array( $this, $action ) );
		}
		add_action( 'wp_ajax_mtfwc_pair_terminal', array( $this, 'mtfwc_pair_terminal' ) );
	}

	public function mtfwc_start_payment(): void {
		$this->with_order( 'start_payment', function ( $order ) {
			$terminal_id = sanitize_text_field( wp_unslash( $_POST['terminal_id'] ?? '' ) );
			$settings    = $this->settings();
			if ( $settings->lock_terminal() ) {
				// Terminal selection is locked: always use the configured default,
				// regardless of what the client submitted.
				$terminal_id = $settings->default_terminal_id();
			}
			return $this->payment_service()->start_payment_for_order( $order, $terminal_id );
		} );
	}
	public function mtfwc_poll_payment(): void { $this->with_order( 'poll_payment', function ( $order ) { return $this->payment_service()->poll_order( $order ); } ); }
	public function mtfwc_cancel_payment(): void { $this->with_order( 'cancel_payment', function ( $order ) { return $this->payment_service()->cancel_order_payment( $order ); } ); }

	public function mtfwc_list_terminals(): void {
		$order_id = absint( $_POST['order_id'] ?? 0 );
		if ( ! $order_id || ! $this->can_access_order( $order_id ) ) {
			wp_send_json_error( __( 'Unauthorized request.', 'mollie-terminal-for-woocommerce' ), 403 );
		}
		try {
			$settings = $this->settings();
			$default  = $settings->default_terminal_id();
			$items    = self::selectable_terminals( self::normalize_terminals( $this->terminal_service()->list_terminals() ), $settings->enabled_terminal_ids() );
			Diagnostics::record( 'info', 'Mollie Terminal list retrieved.', array( 'order_id' => $order_id, 'count' => count( $items ) ) );
			wp_send_json_success( array( 'terminals' => $items, 'default_terminal_id' => $default, 'lock_terminal' => $settings->lock_terminal() ) );
		} catch ( Exception $e ) {
			Diagnostics::record( 'error', 'Mollie Terminal list failed: ' . $e->getMessage(), array( 'order_id' => $order_id ) );
			wp_send_json_error( $e->getMessage(), 500 );
		}
	}

	public static function normalize_terminals( array $terminals ): array {
		$items = array();
		foreach ( $terminals as $terminal ) {
			if ( ! is_array( $terminal ) ) { continue; }
			$id = (string) ( $terminal['id'] ?? '' );
			if ( '' === $id ) { continue; }
			$items[] = array(
				'id'     => $id,
				'label'  => (string) ( $terminal['description'] ?? $terminal['brand'] ?? $id ),
				'status' => (string) ( $terminal['status'] ?? '' ),
				'mode'   => (string) ( $terminal['mode'] ?? '' ),
			);
		}
		return $items;
	}

	/**
	 * Filter normalized terminals down to the ones a cashier may select:
	 * inactive/disabled terminals are dropped (Mollie cannot reactivate them,
	 * so they serve no purpose), and when the merchant configured an
	 * enabled-terminals allowlist only those are kept.
	 */
	public static function selectable_terminals( array $terminals, array $enabled_ids = array() ): array {
		$items = array();
		foreach ( $terminals as $terminal ) {
			$status = strtolower( (string) ( $terminal['status'] ?? '' ) );
			if ( in_array( $status, array( 'inactive', 'disabled' ), true ) ) { continue; }
			if ( $enabled_ids && ! in_array( (string) ( $terminal['id'] ?? '' ), $enabled_ids, true ) ) { continue; }
			$items[] = $terminal;
		}
		return $items;
	}

	/**
	 * Thank-you URL for a paid order. Inside the WooCommerce POS the standard
	 * order-received page is not what the POS watches for, so POS requests
	 * (detected via the X-WCPOS header the frontend sends) get the
	 * /wcpos-checkout/order-received/ variant instead — the same URL the
	 * Stripe/SumUp terminal gateways redirect to.
	 */
	public static function order_return_url( $order ): string {
		if ( function_exists( 'woocommerce_pos_request' ) && woocommerce_pos_request() ) {
			return add_query_arg(
				array( 'key' => $order->get_order_key() ),
				get_home_url( null, '/wcpos-checkout/order-received/' . $order->get_id() )
			);
		}
		return (string) $order->get_checkout_order_received_url();
	}

	public function mtfwc_pair_terminal(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! check_ajax_referer( 'mtfwc_admin_actions', 'nonce', false ) ) { wp_send_json_error( __( 'Security check failed', 'mollie-terminal-for-woocommerce' ), 403 ); }
		try {
			$name = sanitize_text_field( wp_unslash( $_POST['name'] ?? 'WCPOS Terminal' ) );
			wp_send_json_success( $this->terminal_service()->create_pairing_code( $name ) );
		} catch ( Exception $e ) { Logger::log( 'Terminal pairing failed: ' . $e->getMessage() ); wp_send_json_error( $e->getMessage(), 500 ); }
	}

	private function with_order( string $operation, callable $callback ): void {
		try {
			$order_id = absint( $_POST['order_id'] ?? 0 );
			if ( ! $order_id ) {
				wp_send_json_error( __( 'Order ID is required.', 'mollie-terminal-for-woocommerce' ), 400 );
			}
			if ( ! $this->can_access_order( $order_id ) ) {
				wp_send_json_error( __( 'Unauthorized request.', 'mollie-terminal-for-woocommerce' ), 403 );
			}
			Diagnostics::record( 'info', 'Mollie Terminal AJAX request received.', array( 'operation' => $operation, 'order_id' => $order_id ) );
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				Diagnostics::record( 'error', 'Mollie Terminal AJAX request used invalid order.', array( 'operation' => $operation, 'order_id' => $order_id ) );
				wp_send_json_error( __( 'Invalid order.', 'mollie-terminal-for-woocommerce' ), 404 );
			}
			$result = $callback( $order );
			if ( is_array( $result ) && $order->is_paid() ) {
				// The order is already reconciled and paid, so re-submitting the
				// order-pay form would hit WooCommerce's "already paid" guard.
				// Hand the frontend the thank-you URL to navigate to directly.
				$result['redirect_url'] = self::order_return_url( $order );
			}
			Diagnostics::record( 'success', 'Mollie Terminal AJAX request completed.', array( 'operation' => $operation, 'order_id' => $order_id, 'status' => is_array( $result ) ? ( $result['status'] ?? '' ) : '' ) );
			wp_send_json_success( $result );
		} catch ( Exception $e ) {
			Diagnostics::record( 'error', 'Mollie Terminal AJAX failed: ' . $e->getMessage(), array( 'operation' => $operation ) );
			Logger::log( 'Mollie Terminal AJAX failed: ' . $e->getMessage(), array( Logger::CONTEXT_DIAGNOSTICS_RECORDED => true ) );
			wp_send_json_error( $e->getMessage(), 500 );
		}
	}

	private function can_access_order( int $order_id ): bool {
		if ( current_user_can( 'manage_woocommerce' ) || current_user_can( 'edit_shop_order', $order_id ) ) { return true; }
		$token = sanitize_text_field( wp_unslash( $_POST['order_token'] ?? '' ) );
		return $token && hash_equals( self::order_token( $order_id ), $token );
	}

	public static function order_token( int $order_id ): string { return substr( wp_hash( 'mtfwc_order_' . $order_id . wp_salt( 'nonce' ) ), 0, 16 ); }
	private function settings(): Settings { return new Settings(); }
	private function client(): MollieApiClient { return new MollieApiClient( $this->settings()->api_key() ); }
	private function terminal_service(): TerminalService { return new TerminalService( $this->client(), $this->settings() ); }
	private function payment_service(): MolliePaymentService { return new MolliePaymentService( $this->client(), $this->settings(), $this->terminal_service() ); }
}
