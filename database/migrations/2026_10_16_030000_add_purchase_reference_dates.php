<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->string('reference_no')->nullable();
            $table->date('purchase_date')->nullable();
            $table->date('expected_delivery')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('purchases', fn (Blueprint $table) => $table->dropColumn(['reference_no', 'purchase_date', 'expected_delivery']));
    }
};
