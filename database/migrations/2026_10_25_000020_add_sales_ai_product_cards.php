<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void { Schema::table('shop_chat_messages', fn (Blueprint $t) => $t->json('sales_ai_content')->nullable()); }
    public function down(): void { Schema::table('shop_chat_messages', fn (Blueprint $t) => $t->dropColumn('sales_ai_content')); }
};
