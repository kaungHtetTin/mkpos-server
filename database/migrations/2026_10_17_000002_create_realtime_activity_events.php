<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('realtime_activity_events', function (Blueprint $table) {
            $table->id();
            $table->string('audience', 20);
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('event', 80);
            $table->string('event_key', 120)->unique();
            $table->json('payload');
            $table->timestamp('created_at');
            $table->index(['audience', 'business_id', 'id']);
        });
    }
    public function down(): void { Schema::dropIfExists('realtime_activity_events'); }
};
