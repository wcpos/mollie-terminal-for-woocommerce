<?php
namespace WCPOS\WooCommercePOS\MollieTerminal\Services;

use RuntimeException;

/**
 * Mollie did not answer, or answered without deciding: a transport failure, a 5xx, a 429 or a
 * 423. The request may have taken effect, so the caller must not read this as a refusal.
 */
class MollieUnansweredException extends RuntimeException {}
