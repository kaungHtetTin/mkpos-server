<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('password_reset_otps', function (Blueprint $table) {
            $table->string('key', 64)->primary();
            $table->string('code_hash', 64)->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('sent_at')->nullable();
        });
        Schema::table('users', fn (Blueprint $table) => $table->timestamp('password_reset_at')->nullable());
    }
    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('password_reset_at'));
        Schema::dropIfExists('password_reset_otps');
    }
};
