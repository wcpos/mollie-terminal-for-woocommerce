<?php
/**
 * A scripted Mollie behind WordPress's HTTP layer (`pre_http_request`): terminals, point-of-sale
 * payments (idempotent on the Idempotency-Key), the payment read, cancel and refunds, as Mollie's
 * docs describe them. A payment accepted by a terminal is no longer cancelable; a refund is
 * asynchronous.
 *
 * @package WCPOS\WooCommercePOS\MollieTerminal\Tests\Conformance
 */

namespace WCPOS\WooCommercePOS\MollieTerminal\Tests\Conformance;

/** Only the fixture knows provider internals; the suite observes the money path. */
final class Fake_Mollie_Transport {
	/** Every request as WordPress sent it (method, path, body), for debugging a transcript. */
	public $raw = array();
	/** The id of the latest payment created or returned by its idempotency key. */
	public $current;
	private $payments = array();
	private $by_key = array();
	private $terminals;
	private $states = array( 'open' );
	private $scenario = 'create_ok';
	private $refund_status = 'refunded';
	private $cancelable = true;
	private $lost = false;
	private $seq = 0;

	public function __construct() {
		// Mollie exposes activation, not connectivity: both terminals read online to the adapter.
		$this->terminals = array(
			'term_front' => array( 'id' => 'term_front', 'status' => 'active', 'mode' => 'live', 'description' => 'Front counter', 'brand' => 'PAX', 'model' => 'A920' ),
			'term_back'  => array( 'id' => 'term_back', 'status' => 'active', 'mode' => 'live', 'description' => 'Back office', 'brand' => 'PAX', 'model' => 'A920' ),
		);
	}

	/**
	 * Arm a scenario: the states successive adapter fetches observe, how a refund answers, and
	 * whether the payment is still cancelable (a payment the terminal accepted is not).
	 */
	public function script( string $scenario, array $states, string $refund_status = 'refunded', bool $cancelable = true ): void {
		$this->scenario      = $scenario;
		$this->states        = $states;
		$this->refund_status = $refund_status;
		$this->cancelable    = $cancelable;
		$this->lost          = false;
	}

	/** Called by the recording adapter on entry to fetch(): the payment moves to its next scripted state. */
	public function advance( string $id ): void {
		if ( ! isset( $this->payments[ $id ] ) ) {
			return;
		}
		$entry = &$this->payments[ $id ];
		$state = count( $entry['states'] ) > 1 ? array_shift( $entry['states'] ) : $entry['states'][0];
		$this->apply_state( $entry, $state );
	}

	/** A provider-side outcome (what a delivery reports). */
	public function observe( string $id, string $state ): void {
		$this->apply_state( $this->payments[ $id ], $state );
	}

	/** The payment a create keyed on this row id made, even when its response was lost. */
	public function payment_for_key( string $key ): ?string {
		return $this->by_key[ $key ] ?? null;
	}

	/**
	 * The `pre_http_request` filter.
	 *
	 * @param mixed  $pre  Short-circuit value.
	 * @param array  $args Request arguments.
	 * @param string $url  Request URL.
	 * @return mixed
	 * @throws \LogicException On a request no scenario expects.
	 */
	public function handle( $pre, array $args, string $url ) {
		if ( 'api.mollie.com' !== wp_parse_url( $url, PHP_URL_HOST ) ) {
			return $pre;
		}
		$method  = strtoupper( (string) ( $args['method'] ?? 'GET' ) );
		$path    = (string) wp_parse_url( $url, PHP_URL_PATH );
		$body    = isset( $args['body'] ) && '' !== $args['body'] ? json_decode( (string) $args['body'], true ) : array();
		$headers = array_change_key_case( (array) ( $args['headers'] ?? array() ), CASE_LOWER );
		$this->raw[] = array( 'method' => $method, 'path' => $path, 'body' => $body );
		if ( 'Bearer live_conformance' !== ( $headers['authorization'] ?? '' ) ) {
			return self::error( 401, 'Missing authentication, or failed to authenticate' );
		}

		if ( '/v2/terminals' === $path && 'GET' === $method ) {
			return self::ok( array( 'count' => count( $this->terminals ), '_embedded' => array( 'terminals' => array_values( $this->terminals ) ) ) );
		}
		if ( preg_match( '#^/v2/terminals/([^/]+)$#', $path, $m ) && 'GET' === $method ) {
			return isset( $this->terminals[ $m[1] ] ) ? self::ok( $this->terminals[ $m[1] ] ) : self::error( 404, 'No terminal exists with token ' . $m[1] . '.' );
		}
		if ( '/v2/payments' === $path && 'POST' === $method ) {
			$key = (string) ( $headers['idempotency-key'] ?? '' );
			if ( '' !== $key && isset( $this->by_key[ $key ] ) ) {
				// Mollie replays the original response for a key it has seen.
				$this->current = $this->by_key[ $key ];
				return self::ok( $this->view( $this->current ), 201 );
			}
			if ( ! isset( $this->terminals[ (string) ( $body['terminalId'] ?? '' ) ] ) ) {
				return self::error( 422, 'The terminal id is invalid' );
			}
			$id = 'tr_' . str_pad( (string) ( ++$this->seq ), 6, '0', STR_PAD_LEFT );
			$this->payments[ $id ] = array(
				'id'         => $id,
				'states'     => $this->states,
				'state'      => 'open',
				'amount'     => (string) ( $body['amount']['value'] ?? '0.00' ),
				'currency'   => (string) ( $body['amount']['currency'] ?? 'EUR' ),
				'terminal'   => (string) $body['terminalId'],
				'metadata'   => (array) ( $body['metadata'] ?? array() ),
				'cancelable' => $this->cancelable,
				'refunds'    => array(),
				'created'    => time(),
			);
			if ( '' !== $key ) {
				$this->by_key[ $key ] = $id;
			}
			$this->current = $id;
			if ( 'create_indeterminate' === $this->scenario && ! $this->lost ) {
				$this->lost = true; // Created, on the terminal, and the response never arrives.
				return new \WP_Error( 'http_request_failed', 'Response lost after creation' );
			}
			return self::ok( $this->view( $id ), 201 );
		}
		if ( preg_match( '#^/v2/payments/([^/]+)$#', $path, $m ) ) {
			$id = $m[1];
			if ( ! isset( $this->payments[ $id ] ) ) {
				return self::error( 404, 'No payment exists with token ' . $id . '.' );
			}
			if ( 'DELETE' === $method ) {
				$entry = &$this->payments[ $id ];
				if ( ! $this->open( $entry ) || ! $entry['cancelable'] ) {
					return self::error( 422, 'The payment is not cancelable' );
				}
				$this->apply_state( $entry, 'canceled' );
				return self::ok( $this->view( $id ) );
			}
			return self::ok( $this->view( $id ) );
		}
		if ( preg_match( '#^/v2/payments/([^/]+)/refunds$#', $path, $m ) ) {
			$id = $m[1];
			if ( ! isset( $this->payments[ $id ] ) ) {
				return self::error( 404, 'No payment exists with token ' . $id . '.' );
			}
			if ( 'GET' === $method ) {
				return self::ok( array( 'count' => count( $this->payments[ $id ]['refunds'] ), '_embedded' => array( 'refunds' => array_values( $this->payments[ $id ]['refunds'] ) ) ) );
			}
			if ( 'paid' !== $this->payments[ $id ]['state'] ) {
				return self::error( 422, 'The payment is not paid and cannot be refunded' );
			}
			if ( 'failed' === $this->refund_status ) {
				return self::error( 422, 'The refund amount exceeds the refundable amount' );
			}
			$refund = array( 'id' => 're_' . ( ++$this->seq ), 'paymentId' => $id, 'status' => $this->refund_status, 'amount' => $body['amount'], 'description' => (string) ( $body['description'] ?? '' ), 'metadata' => (array) ( $body['metadata'] ?? array() ) );
			$this->payments[ $id ]['refunds'][ $refund['id'] ] = $refund;
			return self::ok( $refund, 201 );
		}
		if ( preg_match( '#^/v2/payments/([^/]+)/refunds/([^/]+)$#', $path, $m ) && 'GET' === $method ) {
			$refund = $this->payments[ $m[1] ]['refunds'][ $m[2] ] ?? null;
			return $refund ? self::ok( $refund ) : self::error( 404, 'No refund exists with token ' . $m[2] . '.' );
		}
		throw new \LogicException( 'Unexpected Mollie request: ' . $method . ' ' . $path );
	}

	/** Whether a payment is still open on Mollie's side (open, pending, authorized). */
	private function open( array $entry ): bool {
		return in_array( $entry['state'], array( 'open', 'pending', 'authorized' ), true );
	}

	/**
	 * Move a payment to a named state.
	 *
	 * @param array  $entry Payment entry, by reference.
	 * @param string $state open, pending, authorized, paid, short (paid 1.00), usd (paid in USD), failed, canceled, expired.
	 */
	private function apply_state( array &$entry, string $state ): void {
		if ( ! in_array( $state, array( 'open', 'pending', 'authorized', 'paid', 'short', 'usd', 'failed', 'canceled', 'expired' ), true ) ) {
			throw new \OutOfBoundsException( 'Unknown payment state: ' . $state );
		}
		$entry['state'] = $state;
	}

	/** The payment as Mollie returns it. */
	private function view( string $id ): array {
		$entry  = $this->payments[ $id ];
		$state  = $entry['state'];
		$status = in_array( $state, array( 'short', 'usd' ), true ) ? 'paid' : $state;
		$payment = array(
			'resource'     => 'payment',
			'id'           => $id,
			'mode'         => 'live',
			'createdAt'    => gmdate( 'c', $entry['created'] ),
			'status'       => $status,
			'isCancelable' => $this->open( $entry ) && $entry['cancelable'],
			'amount'       => array( 'value' => 'short' === $state ? '1.00' : $entry['amount'], 'currency' => 'usd' === $state ? 'USD' : $entry['currency'] ),
			'description'  => 'Order',
			'method'       => 'pointofsale',
			'metadata'     => $entry['metadata'],
			'expiresAt'    => gmdate( 'c', $entry['created'] + 300 ),
			'details'      => array( 'terminalId' => $entry['terminal'] ),
		);
		if ( 'paid' === $status ) {
			$payment['paidAt']  = gmdate( 'c' );
			$payment['details'] += array( 'cardLabel' => 'Visa', 'cardNumber' => '4242', 'cardCountryCode' => 'NL', 'cardAudience' => 'consumer', 'cardFunding' => 'debit', 'receipt' => array( 'authorizationCode' => 'A1B2C3', 'cardReadMethod' => 'contactless', 'cardVerificationMethod' => 'none' ) );
		}
		if ( 'failed' === $status ) {
			$payment['statusReason'] = array( 'code' => 'card_declined', 'message' => 'The card was declined' );
		}
		return $payment;
	}

	/** A WordPress HTTP response. */
	private static function ok( $body, int $status = 200 ): array {
		return array( 'headers' => array(), 'body' => is_string( $body ) ? $body : wp_json_encode( $body ), 'response' => array( 'code' => $status, 'message' => '' ), 'cookies' => array() );
	}

	/** A Mollie error envelope. */
	private static function error( int $status, string $detail ): array {
		return self::ok( array( 'status' => $status, 'title' => 'Error', 'detail' => $detail ), $status );
	}
}
