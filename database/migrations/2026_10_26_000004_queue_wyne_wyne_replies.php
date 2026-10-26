<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_runs', function (Blueprint $table) {
            // Existing historical runs deliberately remain unlinked and are never replayed.
            $table->foreignId('message_id')->nullable()->constrained('ai_messages')->cascadeOnDelete();
            $table->string('actor_role', 20)->nullable();
            $table->string('language', 2)->default('en');
            $table->string('app', 24)->default('business_web');
            $table->unsignedInteger('max_tokens')->nullable();
            $table->json('pricing')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->string('error_code', 40)->nullable();
            $table->index(['status', 'message_id']);
        });
    }

    public function down(): void
    {
        Schema::table('ai_runs', function (Blueprint $table) {
            $table->dropForeign(['message_id']);
            $table->dropIndex(['status', 'message_id']);
            $table->dropColumn(['message_id', 'actor_role', 'language', 'app', 'max_tokens', 'pricing', 'started_at', 'queued_at', 'error_code']);
        });
    }
};
