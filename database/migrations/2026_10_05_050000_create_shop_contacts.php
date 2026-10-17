<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 30);
            $table->string('label', 100)->default('');
            $table->string('value', 500);
            $table->timestamps();
            $table->index(['business_id', 'id']);
        });
    }

    public function down(): void { Schema::dropIfExists('shop_contacts'); }
};
