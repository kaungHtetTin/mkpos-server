<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_plan_id')->constrained()->restrictOnDelete();
            $table->foreignId('business_subscription_id')->nullable()->constrained('business_subscriptions')->nullOnDelete();
            $table->enum('type', ['assignment', 'renewal'])->default('assignment');
            $table->unsignedBigInteger('amount')->default(0);
            $table->string('currency', 20)->default('Ks');
            $table->unsignedInteger('duration_days');
            $table->text('note')->nullable();
            $table->foreignId('created_by_admin_id')->nullable()->constrained('platform_admins')->nullOnDelete();
            $table->timestamp('paid_at');
            $table->timestamps();
            $table->index(['currency', 'paid_at']);
            $table->index(['business_id', 'paid_at']);
        });

        $legacy = DB::table('business_subscriptions as subscriptions')
            ->join('subscription_plans as plans', 'plans.id', '=', 'subscriptions.subscription_plan_id')
            ->select(
                'subscriptions.id as business_subscription_id',
                'subscriptions.business_id',
                'subscriptions.subscription_plan_id',
                'subscriptions.price_paid as amount',
                'subscriptions.note',
                'subscriptions.created_by_admin_id',
                'subscriptions.created_at',
                'plans.currency',
                'plans.duration_days'
            )->get();

        foreach ($legacy as $subscription) {
            DB::table('subscription_payments')->insert([
                'business_id' => $subscription->business_id,
                'subscription_plan_id' => $subscription->subscription_plan_id,
                'business_subscription_id' => $subscription->business_subscription_id,
                'type' => 'assignment',
                'amount' => $subscription->amount,
                'currency' => $subscription->currency,
                'duration_days' => $subscription->duration_days,
                'note' => $subscription->note,
                'created_by_admin_id' => $subscription->created_by_admin_id,
                'paid_at' => $subscription->created_at,
                'created_at' => $subscription->created_at,
                'updated_at' => $subscription->created_at,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_payments');
    }
};
