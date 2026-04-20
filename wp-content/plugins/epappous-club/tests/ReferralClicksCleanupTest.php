<?php

use PHPUnit\Framework\TestCase;

/**
 * @covers EPC_Referral_Clicks_Cleanup
 */
class ReferralClicksCleanupTest extends TestCase {

    public function test_cron_hook_constant(): void {
        $this->assertSame( 'epc_referral_clicks_cleanup_daily', EPC_Referral_Clicks_Cleanup::CRON_HOOK );
    }
}
