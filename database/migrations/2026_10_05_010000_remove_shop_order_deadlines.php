<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement('ALTER TABLE ecommerce_orders MODIFY expires_at TIMESTAMP NULL DEFAULT NULL');
        DB::table('ecommerce_orders')->whereNotIn('status', ['completed', 'cancelled', 'expired'])->update(['expires_at' => null]);
    }

    public function down(): void
    {
        // No deadlines can be reconstructed safely for existing orders.
    }
};
