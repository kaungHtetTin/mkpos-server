<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('storefront_settings', function (Blueprint $table) {
            $table->string('pickup_address')->nullable();
            $table->string('pickup_hours')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('storefront_settings', fn (Blueprint $table) => $table->dropColumn(['pickup_address', 'pickup_hours']));
    }
};
