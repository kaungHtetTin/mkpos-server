<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // offline_created_at remains the original UTC reference. Do not shift online sales.
        DB::table('sales')->where('source', 'offline')->whereNotNull('offline_created_at')
            ->orderBy('id')->chunkById(200, function ($sales) {
                foreach ($sales as $sale) {
                    $local = CarbonImmutable::parse($sale->offline_created_at, 'UTC')
                        ->setTimezone(config('app.timezone'))->toDateTimeString();
                    if ($sale->created_at === $sale->offline_created_at && $sale->created_at !== $local) {
                        DB::table('sales')->where('id', $sale->id)->where('created_at', $sale->offline_created_at)
                            ->update(['created_at' => $local]);
                    }
                }
            });
    }

    public function down(): void
    {
        // Data correction: reverting would reintroduce mixed timestamp conventions.
    }
};
