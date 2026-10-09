<?php
namespace WCPOS\WooCommercePOS\MollieTerminal\Services;

use RuntimeException;

/** Mollie answered the refund POST itself with a refusal: no refund was made. */
class MollieRefundRefusedException extends RuntimeException {}
