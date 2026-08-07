<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use JsonException;
use RuntimeException;

class BusinessBackupService
{
    public const FORMAT = 'mkpos-laravel-business-backup';

    public const VERSION = 1;

    private const MAX_UNCOMPRESSED_BYTES = 100 * 1024 * 1024;

    private const MAX_ROWS = 500000;

    private const TABLES = [
        'products', 'product_prices', 'price_type_rules', 'customers', 'suppliers',
        'sales', 'sale_items', 'purchases', 'purchase_items', 'customer_payments',
        'expenses', 'settings', 'stock_movements',
    ];

    private const INSERT_ORDER = [
        'products', 'customers', 'suppliers', 'price_type_rules', 'settings',
        'product_prices', 'sales', 'sale_items', 'purchases', 'purchase_items',
        'customer_payments', 'expenses', 'stock_movements',
    ];

    private const DELETE_ORDER = [
        'stock_movements', 'expenses', 'customer_payments', 'purchase_items',
        'purchases', 'sale_items', 'sales', 'product_prices', 'price_type_rules',
        'products', 'customers', 'suppliers', 'settings',
    ];

    public function status(): array
    {
        $counts = [];
        foreach (self::TABLES as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return [
            'ok' => true,
            'database' => 'mysql',
            'storage' => 'Laravel server',
            'scope' => 'current_business',
            'schema_version' => DB::table('migrations')->count(),
            'backup_format' => self::FORMAT,
            'backup_version' => self::VERSION,
            'backup_supported' => true,
            'restore_mode' => 'replace_current_business',
            'total_records' => array_sum($counts),
            'counts' => $counts,
        ];
    }

    public function export(int $businessId): string
    {
        $business = DB::table('businesses')->where('id', $businessId)->first();
        if (! $business) {
            throw new RuntimeException('Business not found.');
        }

        $tables = [];
        foreach (self::TABLES as $table) {
            $columns = $this->backupColumns($table);
            $query = DB::table($table)->select($columns);
            if (in_array('id', $columns, true)) {
                $query->orderBy('id');
            }
            $rows = $query->get()->map(fn ($row) => (array) $row)->all();
            if ($table === 'settings') {
                $rows = array_values(array_filter($rows, fn ($row) => $row['key'] !== 'admin_pin_hash'));
            }
            $tables[$table] = $rows;
        }

        $payload = [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'exported_at' => now()->utc()->toIso8601String(),
            'business' => [
                'id' => (int) $business->id,
                'name' => $business->name,
                'slug' => $business->slug,
            ],
            'schema_version' => DB::table('migrations')->count(),
            'tables' => $tables,
        ];

        try {
            $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $document = json_encode([
                'format' => self::FORMAT,
                'version' => self::VERSION,
                'payload' => base64_encode($payloadJson),
                'signature' => hash_hmac('sha256', $payloadJson, $this->signingKey()),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new RuntimeException('The business data could not be encoded.', 0, $exception);
        }

        $compressed = gzencode($document, 9);
        if ($compressed === false) {
            throw new RuntimeException('The backup could not be compressed.');
        }

        return $compressed;
    }

    public function restore(int $businessId, string $archive): array
    {
        $payload = $this->decode($archive);
        if ((int) ($payload['business']['id'] ?? 0) !== $businessId) {
            throw ValidationException::withMessages([
                'backup' => ['This backup belongs to a different business and cannot be restored here.'],
            ]);
        }

        $tables = $payload['tables'] ?? null;
        if (! is_array($tables)) {
            throw ValidationException::withMessages(['backup' => ['The backup does not contain table data.']]);
        }
        $rowCount = 0;
        foreach (self::TABLES as $table) {
            if (! isset($tables[$table]) || ! is_array($tables[$table])) {
                throw ValidationException::withMessages(['backup' => ["The backup is missing {$table} data."]]);
            }
            $rowCount += count($tables[$table]);
        }
        if ($rowCount > self::MAX_ROWS) {
            throw ValidationException::withMessages(['backup' => ['The backup contains too many records.']]);
        }

        $safetyPath = 'business-backups/safety/business-'.$businessId.'-'.now()->format('Ymd-His').'.mkpos-backup';
        Storage::disk('local')->put($safetyPath, $this->export($businessId));

        $adminPinHash = DB::table('settings')->where('key', 'admin_pin_hash')->value('value');
        DB::transaction(function () use ($tables, $adminPinHash) {
            foreach (self::DELETE_ORDER as $table) {
                DB::table($table)->delete();
            }
            foreach (self::INSERT_ORDER as $table) {
                $columns = array_flip($this->backupColumns($table));
                $rows = array_map(function ($row) use ($columns) {
                    if (! is_array($row)) {
                        throw ValidationException::withMessages(['backup' => ['The backup contains an invalid table row.']]);
                    }

                    return array_intersect_key($row, $columns);
                }, $tables[$table]);
                foreach (array_chunk($rows, 500) as $chunk) {
                    if ($chunk) {
                        DB::table($table)->insert($chunk);
                    }
                }
            }
            if ($adminPinHash) {
                DB::table('settings')->updateOrInsert(['key' => 'admin_pin_hash'], ['value' => $adminPinHash]);
            }
        });

        return [
            'ok' => true,
            'restored_at' => now()->utc()->toIso8601String(),
            'records_restored' => $rowCount,
            'safety_backup_created' => true,
        ];
    }

    private function decode(string $archive): array
    {
        if ($archive === '') {
            throw ValidationException::withMessages(['backup' => ['The backup file is empty.']]);
        }
        if (str_starts_with($archive, "\x1f\x8b")) {
            if (strlen($archive) >= 4) {
                $size = unpack('V', substr($archive, -4))[1] ?? 0;
                if ($size > self::MAX_UNCOMPRESSED_BYTES) {
                    throw ValidationException::withMessages(['backup' => ['The expanded backup is too large.']]);
                }
            }
            $documentJson = gzdecode($archive);
            if ($documentJson === false) {
                throw ValidationException::withMessages(['backup' => ['The compressed backup is damaged.']]);
            }
        } else {
            $documentJson = $archive;
        }
        if (strlen($documentJson) > self::MAX_UNCOMPRESSED_BYTES) {
            throw ValidationException::withMessages(['backup' => ['The expanded backup is too large.']]);
        }

        try {
            $document = json_decode($documentJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw ValidationException::withMessages(['backup' => ['The selected file is not a valid MKPOS backup.']]);
        }
        if (($document['format'] ?? '') !== self::FORMAT || (int) ($document['version'] ?? 0) !== self::VERSION) {
            throw ValidationException::withMessages(['backup' => ['This backup format or version is not supported.']]);
        }
        $payloadJson = base64_decode((string) ($document['payload'] ?? ''), true);
        if ($payloadJson === false || ! hash_equals(
            hash_hmac('sha256', $payloadJson, $this->signingKey()),
            (string) ($document['signature'] ?? '')
        )) {
            throw ValidationException::withMessages(['backup' => ['The backup signature is invalid or the file was modified.']]);
        }

        try {
            $payload = json_decode($payloadJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw ValidationException::withMessages(['backup' => ['The backup payload is invalid.']]);
        }
        if (($payload['format'] ?? '') !== self::FORMAT || (int) ($payload['version'] ?? 0) !== self::VERSION) {
            throw ValidationException::withMessages(['backup' => ['The backup payload version is not supported.']]);
        }

        return $payload;
    }

    private function backupColumns(string $table): array
    {
        return array_values(array_diff(
            Schema::getColumnListing($table),
            ['business_id', 'active_barcode']
        ));
    }

    private function signingKey(): string
    {
        $key = (string) config('app.key');
        if ($key === '') {
            throw new RuntimeException('APP_KEY must be configured before backups can be created or restored.');
        }

        return $key;
    }
}
