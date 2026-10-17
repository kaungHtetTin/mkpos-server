<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('office_book_settings', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('founder_one_name', 120);
            $table->string('founder_two_name', 120);
            $table->unsignedInteger('founder_one_bps')->default(5000);
            $table->unsignedInteger('founder_two_bps')->default(5000);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
        DB::table('office_book_settings')->insert(['id' => 1, 'founder_one_name' => 'Kaung Htet Tin', 'founder_two_name' => 'Min Htet Kyaw', 'created_at' => now(), 'updated_at' => now()]);
        Schema::create('office_book_entries', function (Blueprint $table) {
            $table->id();
            $table->uuid('request_key')->unique();
            $table->string('request_hash', 64);
            $table->string('type', 30);
            $table->date('entry_date');
            $table->string('currency', 20);
            $table->decimal('amount', 18, 2);
            $table->unsignedTinyInteger('founder_id')->nullable();
            $table->string('description', 500);
            $table->string('category', 100)->nullable();
            $table->string('reference', 200)->nullable();
            $table->string('counterparty', 200)->nullable();
            $table->string('payment_method', 100)->nullable();
            $table->json('allocation')->nullable();
            $table->foreignId('created_by_admin_id')->nullable()->constrained('platform_admins')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by_admin_id')->nullable()->constrained('platform_admins')->nullOnDelete();
            $table->string('void_reason', 500)->nullable();
            $table->timestamps();
            $table->index(['currency', 'entry_date', 'id']);
            $table->index(['currency', 'type', 'voided_at']);
        });
        Schema::create('office_book_settings_audits', function (Blueprint $table) {
            $table->id();
            $table->json('before');
            $table->json('after');
            $table->foreignId('created_by_admin_id')->nullable()->constrained('platform_admins')->nullOnDelete();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('office_book_settings_audits');
        Schema::dropIfExists('office_book_entries');
        Schema::dropIfExists('office_book_settings');
    }
};
