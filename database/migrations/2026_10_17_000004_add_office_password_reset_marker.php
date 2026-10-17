<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('platform_admins', function (Blueprint $table) {
            $table->timestamp('password_reset_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('platform_admins', fn (Blueprint $table) => $table->dropColumn('password_reset_at'));
    }
};
