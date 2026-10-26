<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_conversations', function (Blueprint $table) {
            // Historical conversations may contain owner data even without recorded tools.
            $table->boolean('requires_owner')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('ai_conversations', fn (Blueprint $table) => $table->dropColumn('requires_owner'));
    }
};
