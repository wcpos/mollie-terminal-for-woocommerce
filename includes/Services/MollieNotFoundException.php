<?php
namespace WCPOS\WooCommercePOS\MollieTerminal\Services;

use RuntimeException;

/** Mollie answered 404: no such resource for this API key. A refusal, with a name. */
class MollieNotFoundException extends RuntimeException {}
