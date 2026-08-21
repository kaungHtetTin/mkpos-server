<?php

namespace Tests\Feature;

use App\Http\Middleware\MonitorTrialRollout;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

class TrialRolloutMonitoringTest extends TestCase
{
    public function test_monitor_records_subscription_and_paid_feature_denials(): void
    {
        Log::shouldReceive('channel')->twice()->with('trial_rollout')->andReturnSelf();
        Log::shouldReceive('warning')->once()->with(
            'trial.subscription_required',
            \Mockery::on(fn ($context) => $context['event'] === 'trial.subscription_required' && $context['reason'] === 'expired')
        );
        Log::shouldReceive('warning')->once()->with(
            'trial.paid_feature_restricted',
            \Mockery::on(fn ($context) => $context['event'] === 'trial.paid_feature_restricted' && $context['capability'] === 'data_export')
        );

        $monitor = app(MonitorTrialRollout::class);
        $monitor->handle(Request::create('/api/products', 'GET'), fn () => response()->json([
            'subscription' => ['reason' => 'expired', 'access_type' => 'trial'],
        ], 402));
        $monitor->handle(Request::create('/api/data/export', 'GET'), fn () => response()->json([
            'code' => 'trial_feature_restricted', 'capability' => 'data_export',
        ], 403));
    }

    public function test_monitor_records_registration_and_conversion_exceptions_without_swallowing_them(): void
    {
        Log::shouldReceive('channel')->twice()->with('trial_rollout')->andReturnSelf();
        Log::shouldReceive('error')->once()->with('trial.registration_failed', \Mockery::type('array'));
        Log::shouldReceive('error')->once()->with('trial.conversion_failed', \Mockery::type('array'));
        $monitor = app(MonitorTrialRollout::class);

        foreach ([
            Request::create('/api/auth/register', 'POST'),
            Request::create('/api/office/businesses/7/subscription', 'PUT'),
        ] as $request) {
            try {
                $monitor->handle($request, fn () => throw new RuntimeException('Controlled rollout failure'));
                $this->fail('The monitored exception should be rethrown.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Controlled rollout failure', $exception->getMessage());
            }
        }
    }
}
