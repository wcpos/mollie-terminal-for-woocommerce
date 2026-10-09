<?php
namespace WCPOS\WooCommercePOS\MollieTerminal\Services;

use RuntimeException;

/**
 * Mollie did not answer the refund POST itself: the refund may exist. The attempt id it was sent
 * under (saved on the refund record before the POST, and the POST's Idempotency-Key) is what a
 * later ask finds it by, or creates it under.
 */
class MollieRefundPostUnansweredException extends RuntimeException {
	/** @var string */
	public $attempt_id;

	public function __construct( string $message, string $attempt_id ) {
		parent::__construct( $message );
		$this->attempt_id = $attempt_id;
	}
}
