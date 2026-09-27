<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_contact_settings', function (Blueprint $table) {
            $table->id();
            $table->json('phone_numbers');
            $table->json('viber_numbers');
            $table->string('telegram_url')->nullable();
            $table->string('email')->nullable();
            $table->string('community_label')->nullable();
            $table->string('community_url')->nullable();
            $table->timestamps();
        });

        DB::table('platform_contact_settings')->insert([
            'id' => 1,
            'phone_numbers' => json_encode(['09688683805', '09682537158', '09792104209']),
            'viber_numbers' => json_encode(['09682537158', '09688683805', '09792104209']),
            'telegram_url' => 'https://t.me/moekaungdev',
            'email' => null,
            'community_label' => null,
            'community_url' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_contact_settings');
    }
};
