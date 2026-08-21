<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $trialSlug = (string) config('mkpos.trial.plan_slug', 'free-trial');
        if (DB::table('subscription_plans')->where('slug', $trialSlug)->exists()) {
            throw new RuntimeException(
                "The reserved trial plan slug [{$trialSlug}] is already in use. Rename that plan before running this migration."
            );
        }

        $this->alterSubscriptionPlans(function (Blueprint $table) {
            $table->boolean('is_system')->default(false)->after('features');
            $table->boolean('is_public')->default(true)->after('is_system');
            $table->index(
                ['is_public', 'is_active', 'sort_order'],
                'subscription_plans_public_active_order_index'
            );
        });

        Schema::table('business_subscriptions', function (Blueprint $table) {
            $table->enum('access_type', ['trial', 'paid'])->default('paid')->after('status');
            $table->index(
                ['business_id', 'access_type', 'status', 'ends_at'],
                'business_subscriptions_access_status_end_index'
            );
        });

        DB::table('subscription_plans')->insert([
            'name' => 'Free Trial',
            'slug' => $trialSlug,
            'description' => 'System-managed one-month trial for newly registered businesses.',
            'price' => 0,
            'currency' => 'Ks',
            'duration_days' => 30,
            'features' => json_encode([
                'POS',
                'Products',
                'Purchases',
                'Suppliers',
                'Customers',
                'Expenses',
                'Transactions',
                'Reports',
            ]),
            // Disabled as defense in depth for clients deployed before is_public.
            'is_active' => false,
            'is_system' => true,
            'is_public' => false,
            'sort_order' => 999999,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        $trialSlug = (string) config('mkpos.trial.plan_slug', 'free-trial');
        $trialPlan = DB::table('subscription_plans')->where('slug', $trialSlug)->where('is_system', true)->first();

        if ($trialPlan) {
            $referenced = DB::table('business_subscriptions')->where('subscription_plan_id', $trialPlan->id)->exists()
                || DB::table('subscription_requests')->where('subscription_plan_id', $trialPlan->id)->exists()
                || DB::table('subscription_payments')->where('subscription_plan_id', $trialPlan->id)->exists();

            if ($referenced) {
                throw new RuntimeException(
                    'The trial-classification migration cannot be rolled back while trial plan records are referenced.'
                );
            }

            DB::table('subscription_plans')->where('id', $trialPlan->id)->delete();
        }

        Schema::table('business_subscriptions', function (Blueprint $table) {
            $table->dropIndex('business_subscriptions_access_status_end_index');
            $table->dropColumn('access_type');
        });

        $this->alterSubscriptionPlans(function (Blueprint $table) {
            $table->dropIndex('subscription_plans_public_active_order_index');
            $table->dropColumn(['is_system', 'is_public']);
        });
    }

    private function alterSubscriptionPlans(Closure $change): void
    {
        $isAffectedMariaDb = DB::getDriverName() === 'mysql'
            && str_contains((string) DB::selectOne('SELECT VERSION() AS version')->version, 'MariaDB');

        if (! $isAffectedMariaDb) {
            Schema::table('subscription_plans', $change);

            return;
        }

        // MariaDB 10.4 can retain a stale internal table alias for the inline
        // JSON check generated when this table was originally created. Convert
        // that legacy inline check once; explicit checks created here are safe
        // across subsequent up/down ALTER operations.
        $checkExists = (bool) DB::selectOne(
            "SELECT COUNT(*) AS aggregate
             FROM information_schema.CHECK_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE()
               AND TABLE_NAME = 'subscription_plans'
               AND CONSTRAINT_NAME = 'features'"
        )->aggregate;
        if ($checkExists) {
            // A dump/import can make MariaDB retain the original query alias
            // internally even though SHOW CREATE TABLE displays `features`.
            // Recreating the check before ALTER removes that stale binding.
            DB::statement('ALTER TABLE subscription_plans DROP CONSTRAINT features');
        } else {
            DB::statement(
                'ALTER TABLE subscription_plans MODIFY features LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL'
            );
        }

        try {
            Schema::table('subscription_plans', $change);
        } finally {
            $checkExists = (bool) DB::selectOne(
                "SELECT COUNT(*) AS aggregate
                 FROM information_schema.CHECK_CONSTRAINTS
                 WHERE CONSTRAINT_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'subscription_plans'
                   AND CONSTRAINT_NAME = 'features'"
            )->aggregate;
            if (! $checkExists) {
                DB::statement('ALTER TABLE subscription_plans ADD CONSTRAINT features CHECK (json_valid(features))');
            }
        }
    }
};
