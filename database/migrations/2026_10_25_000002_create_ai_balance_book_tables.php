<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_balance_accounts', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->bigInteger('balance_nanos')->default(0);
            $table->timestamps();
        });
        DB::table('ai_balance_accounts')->insert(['id' => 1, 'balance_nanos' => 0, 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
        Schema::create('ai_balance_entries', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20);
            $table->bigInteger('amount_nanos');
            $table->bigInteger('balance_after_nanos');
            $table->uuid('request_key')->nullable()->unique();
            $table->uuid('run_id')->nullable()->unique();
            $table->unsignedBigInteger('business_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('admin_id')->nullable();
            $table->string('recorded_by', 120)->nullable();
            $table->string('description', 255);
            $table->string('reference', 120)->nullable();
            $table->string('model', 100)->nullable();
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->json('pricing')->nullable();
            $table->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_balance_entries');
        Schema::dropIfExists('ai_balance_accounts');
    }
};
