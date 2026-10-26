<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

class OfficeBusinessDeletion
{
    public function delete(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);

        $screenshotPaths = DB::transaction(function () use ($ids) {
            DB::table('storefront_settings')->whereIn('business_id', $ids)->orderBy('id')->lockForUpdate()->get();
            foreach ($ids as $businessId) app(EcommerceInventory::class)->assertMaintenanceAllowed((int) $businessId);
            $found = DB::table('businesses')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->pluck('id')
                ->map(fn ($id) => (int) $id)->all();
            abort_if($found !== $ids, 404, 'One or more businesses were not found. Refresh the list and try again.');

            // Business locks serialize with Sales AI claims and top-up reviews.
            // Allow an active provider call to settle before permanently deleting it.
            if (Schema::hasTable('sales_ai_runs')) {
                abort_if(DB::table('sales_ai_runs')->whereIn('business_id', $ids)->where('status', 'processing')->exists(), 409, 'A Sales AI reply is being generated. Try deleting the business after it finishes.');
                foreach (['sales_ai_entries', 'sales_ai_topups', 'sales_ai_runs', 'sales_ai_settings', 'sales_ai_wallets'] as $table) {
                    DB::table($table)->whereIn('business_id', $ids)->delete();
                }
            }

            $screenshots = DB::table('subscription_requests')->whereIn('business_id', $ids)
                ->whereNotNull('payment_screenshot_path')->pluck('payment_screenshot_path')->all();
            $userIds = DB::table('users')->whereIn('business_id', $ids)->pluck('id')->all();
            if ($userIds) {
                DB::table('personal_access_tokens')->where('tokenable_type', User::class)
                    ->whereIn('tokenable_id', $userIds)->delete();
            }

            // These rows reference products without a product-delete cascade.
            DB::table('sale_items')->whereIn('business_id', $ids)->delete();
            DB::table('purchase_items')->whereIn('business_id', $ids)->delete();
            abort_unless(DB::table('businesses')->whereIn('id', $ids)->delete() === count($ids), 409, 'Business deletion could not be completed.');

            return $screenshots;
        });

        $disk = Storage::disk('local');
        $failedPaths = [];
        foreach ($ids as $id) {
            foreach (["product-photos/{$id}", "business-logos/{$id}", "sales-ai-payment-proofs/{$id}", "sales-ai-action-proofs/{$id}"] as $directory) {
                try {
                    if ($disk->directoryExists($directory) && ! $disk->deleteDirectory($directory)) {
                        $failedPaths[] = $directory;
                    }
                } catch (Throwable $error) {
                    $failedPaths[] = $directory;
                    Log::warning('Business file cleanup failed', ['path' => $directory, 'error' => $error->getMessage()]);
                }
            }
        }

        $paths = array_values(array_unique(array_filter($screenshotPaths, fn ($path) =>
            is_string($path) && str_starts_with($path, 'subscription-payment-screenshots/')
        )));
        try {
            foreach ($disk->files('business-backups/safety') as $path) {
                foreach ($ids as $id) {
                    if (str_starts_with(basename($path), "business-{$id}-") && str_ends_with($path, '.mkpos-backup')) {
                        $paths[] = $path;
                        break;
                    }
                }
            }
        } catch (Throwable $error) {
            $failedPaths[] = 'business-backups/safety';
            Log::warning('Business backup cleanup failed', ['business_ids' => $ids, 'error' => $error->getMessage()]);
        }

        foreach (array_unique($paths) as $path) {
            try {
                if ($disk->exists($path) && ! $disk->delete($path)) {
                    $failedPaths[] = $path;
                }
            } catch (Throwable $error) {
                $failedPaths[] = $path;
                Log::warning('Business file cleanup failed', ['path' => $path, 'error' => $error->getMessage()]);
            }
        }
        if ($failedPaths) {
            Log::warning('Deleted businesses have files requiring cleanup', ['business_ids' => $ids, 'paths' => $failedPaths]);
        }

        return ['ok' => true, 'deleted_count' => count($ids), 'file_cleanup_complete' => ! $failedPaths];
    }
}
