<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::transaction(function () {
            // Also repairs receipts written by the previous running application
            // during deployment. NULL always means legacy book #2, never the
            // current configurable default. Existing assignments are immutable.
            if (!DB::table('office_bank_books')->where('id', 2)->exists()) {
                throw new RuntimeException('Legacy subscription bank book #2 is missing. Restore it before backfilling receipts.');
            }
            DB::table('subscription_payments')->whereNull('account_id')->update(['account_id' => 2]);
        });
    }

    public function down(): void
    {
        // Preserve explicit historical assignments. Clearing them would make
        // legacy receipts indistinguishable from later payments into this book.
    }
};
