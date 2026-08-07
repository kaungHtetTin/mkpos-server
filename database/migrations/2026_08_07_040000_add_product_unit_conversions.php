<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('base_unit', 50)->default('Unit')->after('category');
            $table->string('purchase_unit', 50)->nullable()->after('base_unit');
            $table->decimal('purchase_conversion_factor', 15, 3)->default(1)->after('purchase_unit');
        });

        Schema::table('purchase_items', function (Blueprint $table) {
            $table->string('unit_name', 50)->default('Unit')->after('product_name');
            $table->decimal('conversion_factor', 15, 6)->default(1)->after('unit_name');
            $table->decimal('base_quantity', 15, 3)->default(0)->after('foc_quantity');
            $table->decimal('base_foc_quantity', 15, 3)->default(0)->after('base_quantity');
        });

        DB::table('purchase_items')->update([
            'base_quantity' => DB::raw('quantity'),
            'base_foc_quantity' => DB::raw('foc_quantity'),
        ]);
    }

    public function down()
    {
        Schema::table('purchase_items', function (Blueprint $table) {
            $table->dropColumn(['unit_name', 'conversion_factor', 'base_quantity', 'base_foc_quantity']);
        });
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['base_unit', 'purchase_unit', 'purchase_conversion_factor']);
        });
    }
};
