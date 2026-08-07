<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('platform_admins', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->boolean('is_active')->default(true);
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('price')->default(0);
            $table->string('currency', 20)->default('Ks');
            $table->unsignedInteger('duration_days')->default(30);
            $table->json('features')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('business_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_plan_id')->constrained()->restrictOnDelete();
            $table->enum('status', ['active', 'expired', 'cancelled'])->default('active');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->unsignedBigInteger('price_paid')->default(0);
            $table->text('note')->nullable();
            $table->foreignId('created_by_admin_id')->nullable()->constrained('platform_admins')->nullOnDelete();
            $table->timestamps();
            $table->index(['business_id', 'status', 'ends_at']);
        });

        Schema::create('subscription_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_plan_id')->constrained()->restrictOnDelete();
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->text('message')->nullable();
            $table->text('admin_note')->nullable();
            $table->foreignId('reviewed_by_admin_id')->nullable()->constrained('platform_admins')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['business_id', 'status']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('subscription_requests');
        Schema::dropIfExists('business_subscriptions');
        Schema::dropIfExists('subscription_plans');
        Schema::dropIfExists('platform_admins');
    }
};
