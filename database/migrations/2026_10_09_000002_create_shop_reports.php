<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('shop_reports', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->foreignId('customer_id')->constrained('platform_customers')->cascadeOnDelete();
            $t->string('reason', 40);
            $t->text('details');
            $t->string('order_reference', 40)->nullable();
            $t->string('status', 20)->default('open');
            $t->string('open_key', 80)->nullable()->unique();
            $t->uuid('submission_key');
            $t->text('office_response')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['customer_id', 'submission_key']);
            $t->index(['status', 'id']);
            $t->index(['customer_id', 'business_id', 'id']);
        });
        Schema::create('shop_report_updates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('report_id')->constrained('shop_reports')->cascadeOnDelete();
            $t->unsignedBigInteger('admin_id')->nullable();
            $t->string('status', 20);
            $t->text('internal_note');
            $t->text('office_response')->nullable();
            $t->timestamp('created_at');
            $t->index(['report_id', 'id']);
        });
    }
    public function down(): void { Schema::dropIfExists('shop_report_updates'); Schema::dropIfExists('shop_reports'); }
};
