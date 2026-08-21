<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TrialRolloutCheck extends Command
{
    protected $signature = 'trial:rollout-check {--json : Emit machine-readable JSON}';

    protected $description = 'Validate free-trial rollout configuration, schema, system plan, and data invariants';

    public function handle(): int
    {
        $slug = (string) config('mkpos.trial.plan_slug', 'free-trial');
        $plan = Schema::hasTable('subscription_plans')
            ? DB::table('subscription_plans')->where('slug', $slug)->first()
            : null;
        $migration = Schema::hasTable('migrations')
            ? DB::table('migrations')->where('migration', '2026_08_20_000000_add_trial_subscription_classification')->exists()
            : false;
        $schemaReady = Schema::hasColumns('subscription_plans', ['is_system', 'is_public'])
            && Schema::hasColumn('business_subscriptions', 'access_type');
        $duplicateBusinesses = $schemaReady
            ? DB::table('business_subscriptions')->where('access_type', 'trial')
                ->select('business_id')->groupBy('business_id')->havingRaw('COUNT(*) > 1')->count()
            : null;
        $trialCount = $schemaReady
            ? DB::table('business_subscriptions')->where('access_type', 'trial')->count()
            : null;
        $paidCount = $schemaReady
            ? DB::table('business_subscriptions')->where('access_type', 'paid')->count()
            : null;

        $checks = [
            'migration_recorded' => $migration,
            'schema_ready' => $schemaReady,
            'system_plan_ready' => $plan
                && (bool) $plan->is_system
                && ! (bool) $plan->is_public
                && ! (bool) $plan->is_active
                && (int) $plan->price === 0,
            'no_duplicate_trial_businesses' => $duplicateBusinesses === 0,
            'duration_months_valid' => (int) config('mkpos.trial.duration_months') >= 1,
            'offline_grace_valid' => (int) config('mkpos.trial.offline_sync_grace_days') >= 0,
        ];
        $result = [
            'ok' => ! in_array(false, $checks, true),
            'configuration' => [
                'enabled' => (bool) config('mkpos.trial.enabled'),
                'plan_slug' => $slug,
                'duration_months' => (int) config('mkpos.trial.duration_months'),
                'offline_sync_grace_days' => (int) config('mkpos.trial.offline_sync_grace_days'),
                'monitor_log_channel' => (string) config('mkpos.trial.monitor_log_channel'),
            ],
            'checks' => $checks,
            'counts' => [
                'trial_subscriptions' => $trialCount,
                'paid_subscriptions' => $paidCount,
                'duplicate_trial_businesses' => $duplicateBusinesses,
            ],
        ];

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info($result['ok'] ? 'Trial rollout check passed.' : 'Trial rollout check failed.');
            $this->table(['Check', 'Result'], collect($checks)->map(
                fn ($passed, $name) => [$name, $passed ? 'PASS' : 'FAIL']
            )->values()->all());
            $this->line(sprintf(
                'Flag: %s | Duration: %d month(s) | Offline grace: %d day(s) | Trials: %s | Paid: %s',
                $result['configuration']['enabled'] ? 'enabled' : 'disabled',
                $result['configuration']['duration_months'],
                $result['configuration']['offline_sync_grace_days'],
                $trialCount ?? 'unavailable',
                $paidCount ?? 'unavailable'
            ));
        }

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
