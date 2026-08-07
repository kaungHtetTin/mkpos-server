<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->string('source', 20)->default('online')->after('status');
            $table->uuid('offline_sale_uuid')->nullable()->after('source');
            $table->timestamp('offline_created_at')->nullable()->after('offline_sale_uuid');
            $table->unique('offline_sale_uuid');
        });
    }

    public function down()
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropUnique(['offline_sale_uuid']);
            $table->dropColumn(['source', 'offline_sale_uuid', 'offline_created_at']);
        });
    }
};
