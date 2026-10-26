<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['ai_runs', 'ai_messages', 'ai_balance_entries'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                // Null means unreported or historical; it does not mean a confirmed zero.
                $table->unsignedBigInteger('cached_input_tokens')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['ai_runs', 'ai_messages', 'ai_balance_entries'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('cached_input_tokens'));
        }
    }
};
