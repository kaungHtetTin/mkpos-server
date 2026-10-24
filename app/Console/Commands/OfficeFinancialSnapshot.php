<?php

namespace App\Console\Commands;

use App\Services\OfficeFinancialSnapshot as Snapshot;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class OfficeFinancialSnapshot extends Command
{
    protected $signature = 'office:financial-snapshot {--verify= : Snapshot filename to compare against in private storage}';
    protected $description = 'Save Office financial history and statistics, or verify them after an additive migration';

    public function handle(Snapshot $snapshots): int
    {
        $disk = Storage::disk('local'); $directory = 'office-financial-snapshots';
        try {
            if ($filename = $this->option('verify')) {
                if (!preg_match('/^[a-zA-Z0-9_-]+\.json$/', $filename)) throw new \RuntimeException('Pass only the snapshot filename, without a directory.');
                if (!$disk->exists($directory.'/'.$filename)) throw new \RuntimeException('Snapshot not found in private storage.');
                $before = json_decode($disk->get($directory.'/'.$filename), true, 512, JSON_THROW_ON_ERROR);
                if (($before['format_version'] ?? null) !== 1 || empty($before['tables'])) throw new \RuntimeException('Unsupported financial snapshot format.');
                $differences = $snapshots->differences($before, $snapshots->capture($before));
                foreach ($differences as $difference) $this->error($difference);
                if ($differences) return self::FAILURE;
                $this->info('Financial history and statistics match the saved snapshot.');
                return self::SUCCESS;
            }
            $filename = now()->utc()->format('Ymd_His').'_'.Str::uuid().'.json';
            if (!$disk->put($directory.'/'.$filename, json_encode($snapshots->capture(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR))) throw new \RuntimeException('Could not save the financial snapshot.');
            $this->info('Financial snapshot saved in private storage: '.$disk->path($directory.'/'.$filename));
            $this->line('Verify with: php artisan office:financial-snapshot --verify='.$filename);
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }
    }
}
