<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_assistant_settings', fn (Blueprint $table) => $table->decimal('sales_ai_markup_percent', 7, 2)->default(5));
    }

    public function down(): void
    {
        Schema::table('ai_assistant_settings', fn (Blueprint $table) => $table->dropColumn('sales_ai_markup_percent'));
    }
};
