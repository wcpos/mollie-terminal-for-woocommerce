<?php
/**
 * Pro's money-path lessons against the real Mollie adapter over a scripted Mollie.
 *
 * @package WCPOS\WooCommercePOS\MollieTerminal\Tests\Conformance
 */

namespace WCPOS\WooCommercePOS\MollieTerminal\Tests\Conformance;

use WCPOS\WooCommercePOSPro\Tests\Conformance\Conformance_Fixture;
use WCPOS\WooCommercePOSPro\Tests\Conformance\Provider_Conformance_Test_Case;

require_once __DIR__ . '/Mollie_Conformance_Fixture.php';

/** The transcripts in ./transcripts are the certified record; a change there is a re-certification. */
class Test_Mollie_Provider_Conformance extends Provider_Conformance_Test_Case {
	protected function fixture(): Conformance_Fixture {
		return new Mollie_Conformance_Fixture();
	}
}
