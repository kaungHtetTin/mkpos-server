<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void
    {
        Schema::table('shop_chat_messages', function (Blueprint $table) {
            $table->string('image_path')->nullable();
            $table->string('image_mime', 40)->nullable();
            $table->string('image_hash', 64)->nullable();
            $table->timestamp('image_expires_at')->nullable()->index();
            $table->timestamp('image_deleted_at')->nullable();
        });
    }
    public function down(): void
    {
        Schema::table('shop_chat_messages', function (Blueprint $table) {
            $table->dropIndex(['image_expires_at']);
            $table->dropColumn(['image_path', 'image_mime', 'image_hash', 'image_expires_at', 'image_deleted_at']);
        });
    }
};
