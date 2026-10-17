<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('shop_customer_controls', function (Blueprint $table) {
            $table->string('block_reason', 200)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shop_customer_controls', function (Blueprint $table) {
            $table->dropColumn('block_reason');
        });
    }
};
