<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('realtime_activity_locks')) Schema::create('realtime_activity_locks', function (Blueprint $table) { $table->string('audience', 20)->primary(); });
        DB::table('realtime_activity_locks')->insertOrIgnore([['audience' => 'owner'], ['audience' => 'office']]);
    }
    public function down(): void { Schema::dropIfExists('realtime_activity_locks'); }
};
