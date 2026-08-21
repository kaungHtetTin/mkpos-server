<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class TrialRolloutCommandTest extends TestCase
{
    use DatabaseTransactions;

    public function test_rollout_check_reports_configuration_schema_plan_and_counts(): void
    {
        $exitCode = Artisan::call('trial:rollout-check', ['--json' => true]);
        $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertTrue($result['ok']);
        $this->assertSame(1, $result['configuration']['duration_months']);
        $this->assertSame(7, $result['configuration']['offline_sync_grace_days']);
        $this->assertNotEmpty($result['configuration']['monitor_log_channel']);
        $this->assertNotContains(false, $result['checks'], true);
        $this->assertArrayHasKey('trial_subscriptions', $result['counts']);
        $this->assertArrayHasKey('paid_subscriptions', $result['counts']);
    }
}
