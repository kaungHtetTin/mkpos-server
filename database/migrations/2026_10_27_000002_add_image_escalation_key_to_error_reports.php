<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('error_reports', fn (Blueprint $table) => $table->string('image_escalation_key', 64)->nullable()->unique());
    }

    public function down(): void
    {
        Schema::table('error_reports', function (Blueprint $table) {
            $table->dropUnique(['image_escalation_key']);
            $table->dropColumn('image_escalation_key');
        });
    }
};
