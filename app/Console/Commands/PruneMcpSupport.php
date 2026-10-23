<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PruneMcpSupport extends Command
{
    protected $signature = 'mcp:support-prune';

    protected $description = 'Remove expired delegated support tokens and expired unused private payment evidence.';

    public function handle(): int
    {
        $tokens = DB::table('mcp_support_tokens')->where('expires_at', '<=', now())->delete();
        $removed = 0;
        DB::table('mcp_support_evidence')->whereNull('consumed_at')->where('expires_at', '<=', now())->orderBy('id')
            ->chunkById(100, function ($rows) use (&$removed): void {
                foreach ($rows as $row) {
                    $removed += DB::transaction(function () use ($row): int {
                        $current = DB::table('mcp_support_evidence')->where('id', $row->id)->whereNull('consumed_at')
                            ->where('expires_at', '<=', now())->lockForUpdate()->first();
                        if (! $current || ! str_starts_with($current->file_path, 'mcp-payment-evidence/')) {
                            return 0;
                        }
                        // Never race a proof submission or remove attached evidence.
                        if (DB::table('subscription_requests')->where('payment_screenshot_path', $current->file_path)->exists()) {
                            return 0;
                        }
                        if (Storage::disk('local')->exists($current->file_path) && ! Storage::disk('local')->delete($current->file_path)) {
                            return 0;
                        }

                        return DB::table('mcp_support_evidence')->where('id', $current->id)->delete();
                    });
                }
            });
        $this->info("Removed {$tokens} expired tokens and {$removed} unused uploads. Retained action audit history and submitted proofs.");

        return self::SUCCESS;
    }
}
