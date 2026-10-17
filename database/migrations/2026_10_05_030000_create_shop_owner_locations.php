<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('shop_owner_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('owner_id')->constrained('users');
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->double('accuracy');
            $table->timestamp('captured_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_owner_locations');
    }
};
