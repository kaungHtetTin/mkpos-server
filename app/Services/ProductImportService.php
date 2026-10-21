<?php

namespace App\Services;

use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class ProductImportService
{
    public const MAX_ROWS = 5000;

    public const CHUNK_SIZE = 50;

    public function __construct(private ProductWriter $writer, private TenantContext $tenant) {}

    public function find(string $id): object
    {
        $import = DB::table('product_imports')->where('business_id', $this->tenant->requireId())->where('id', $id)->first();
        abort_unless($import, 404, 'Import not found.');

        return $import;
    }

    public function summary(string $id): array
    {
        $import = $this->find($id);
        $counts = DB::table('product_import_rows')->where('import_id', $id)
            ->select('status')->selectRaw('COUNT(*) as total')->groupBy('status')->pluck('total', 'status');

        return ['id' => $id, 'filename' => $import->filename, 'status' => $import->status,
            'total_rows' => $import->total_rows, 'ready' => (int) ($counts['ready'] ?? 0),
            'invalid' => (int) ($counts['invalid'] ?? 0), 'imported' => (int) ($counts['imported'] ?? 0),
            'skipped' => (int) ($counts['skipped'] ?? 0), 'failed' => (int) ($counts['failed'] ?? 0),
            'created_at' => $import->created_at, 'updated_at' => $import->updated_at];
    }

    public function preview(string $csv, string $filename, int $userId): array
    {
        $businessId = $this->tenant->requireId();
        $fingerprint = hash('sha256', $csv);
        // The same file resumes its saved checkpoints. A new upload can also
        // replace products that were deleted after that import completed.
        $existing = DB::table('product_imports')->where('business_id', $businessId)->where('fingerprint', $fingerprint)->value('id');
        if ($existing) {
            return $this->requeueRemovedProducts($existing);
        }
        if (! mb_check_encoding($csv, 'UTF-8')) {
            $this->fileError('Save the CSV using UTF-8 encoding.');
        }
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, preg_replace('/^\xEF\xBB\xBF/', '', $csv));
        rewind($stream);
        try {
            $headers = fgetcsv($stream, 0, ',', '"', '');
            if (! $headers || $headers === [null]) {
                $this->fileError('Import file is empty.');
            }
            $headers = array_map(fn ($value) => mb_strtolower(trim((string) $value)), $headers);
            if (! in_array('name', $headers, true)) {
                $this->fileError('The CSV must contain a Name column.');
            }
            if (count(array_unique($headers)) !== count($headers) || in_array('', $headers, true)) {
                $this->fileError('Column headings must be non-empty and unique.');
            }
            $configured = explode(',', DB::table('settings')->where('key', 'price_types')->value('value') ?: 'Retail');
            $types = collect([...$configured, 'Retail', 'Wholesale', 'Credit'])->map('trim')->filter()
                ->unique(fn ($name) => mb_strtolower($name))->values()->all();
            $rows = [];
            $seen = [];
            $number = 1;
            while (($cells = fgetcsv($stream, 0, ',', '"', '')) !== false) {
                $number++;
                if ($cells === [null] || count(array_filter($cells, fn ($cell) => trim((string) $cell) !== '')) === 0) {
                    continue;
                }
                if (count($rows) >= self::MAX_ROWS) {
                    $this->fileError('Import at most '.self::MAX_ROWS.' products per file.');
                }
                $payload = [];
                $status = 'ready';
                $errors = [];
                try {
                    if (count($cells) !== count($headers)) {
                        throw ValidationException::withMessages(['row' => 'The number of values does not match the column headings.']);
                    }
                    $row = array_combine($headers, array_map(fn ($cell) => trim((string) $cell), $cells));
                    $payload = $this->payload($row, $types);
                    $duplicate = $this->duplicate($payload);
                    foreach (['sku', 'barcode'] as $field) {
                        $value = mb_strtolower($payload[$field]);
                        if ($value !== '' && isset($seen[$field][$value])) {
                            $duplicate = 'The '.$field.' repeats row '.$seen[$field][$value].'.';
                        }
                    }
                    if ($duplicate) {
                        $status = 'skipped';
                        $errors = [$duplicate];
                    } else {
                        $payload = $this->writer->validate(Request::create('/api/products', 'POST', $payload));
                        foreach (['sku', 'barcode'] as $field) {
                            $value = mb_strtolower((string) ($payload[$field] ?? ''));
                            if ($value !== '') {
                                $seen[$field][$value] = $number;
                            }
                        }
                    }
                } catch (ValidationException $error) {
                    $status = 'invalid';
                    $errors = collect($error->errors())->flatten()->all();
                } catch (HttpExceptionInterface $error) {
                    if ($error->getStatusCode() !== 422) {
                        throw $error;
                    }
                    $status = 'invalid';
                    $errors = [$error->getMessage()];
                }
                $rows[] = ['row_number' => $number, 'name' => mb_substr((string) ($payload['name'] ?? ''), 0, 255),
                    'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'status' => $status,
                    'errors' => json_encode($errors, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()];
            }
            if (! $rows) {
                $this->fileError('Import file has no product rows.');
            }
        } finally {
            fclose($stream);
        }

        // Serialize creation for this business so simultaneous uploads return the same import.
        $id = DB::transaction(function () use ($businessId, $fingerprint, $filename, $userId, $rows) {
            DB::table('businesses')->where('id', $businessId)->lockForUpdate()->first();
            $existing = DB::table('product_imports')->where('business_id', $businessId)->where('fingerprint', $fingerprint)->value('id');
            if ($existing) {
                return $existing;
            }
            $id = (string) Str::uuid();
            DB::table('product_imports')->insert(['id' => $id, 'business_id' => $businessId, 'user_id' => $userId,
                'filename' => mb_substr($filename, 0, 255), 'fingerprint' => $fingerprint, 'status' => 'preview',
                'total_rows' => count($rows), 'created_at' => now(), 'updated_at' => now()]);
            foreach (array_chunk($rows, 100) as $chunk) {
                DB::table('product_import_rows')->insert(array_map(fn ($row) => $row + ['import_id' => $id], $chunk));
            }

            return $id;
        });

        return $this->requeueRemovedProducts($id);
    }

    private function requeueRemovedProducts(string $id): array
    {
        $businessId = $this->tenant->requireId();
        DB::transaction(function () use ($id, $businessId) {
            // Processing and repeated uploads share this lock. Once queued,
            // retries resume the same rows instead of resetting their progress.
            $import = DB::table('product_imports')->where('business_id', $businessId)->where('id', $id)->lockForUpdate()->first();
            if ($import->status !== 'completed') {
                return;
            }
            $rows = DB::table('product_import_rows')->where('import_id', $id)->where('status', 'imported')->get(['id', 'product_id']);
            $activeIds = DB::table('products')->where('business_id', $businessId)->where('is_active', true)
                ->whereIn('id', $rows->pluck('product_id')->filter()->all())->pluck('id')->flip();
            $removed = $rows->reject(fn ($row) => $activeIds->has($row->product_id))->pluck('id')->all();
            if (! $removed) {
                return;
            }
            foreach (array_chunk($removed, 500) as $ids) {
                DB::table('product_import_rows')->where('import_id', $id)->whereIn('id', $ids)
                    ->update(['status' => 'ready', 'product_id' => null, 'errors' => '[]', 'updated_at' => now()]);
            }
            DB::table('product_imports')->where('id', $id)->where('business_id', $businessId)
                ->update(['status' => 'preview', 'updated_at' => now()]);
        }, 3);

        return $this->summary($id);
    }

    public function process(string $id, int $userId): array
    {
        $this->find($id);
        DB::transaction(function () use ($id, $userId) {
            // The import lock serializes concurrent/retried chunk requests. Product and progress
            // commit together: a crash rolls back the unfinished chunk, never just its checkpoint.
            $import = DB::table('product_imports')->where('id', $id)->where('business_id', $this->tenant->requireId())->lockForUpdate()->first();
            if ($import->status === 'completed') {
                return;
            }
            abort_if(DB::table('product_import_rows')->where('import_id', $id)->where('status', 'invalid')->exists(), 422, 'Fix invalid rows and upload the corrected CSV before importing.');
            DB::table('product_imports')->where('id', $id)->update(['status' => 'processing', 'updated_at' => now()]);
            $rows = DB::table('product_import_rows')->where('import_id', $id)->where('status', 'ready')->orderBy('row_number')->limit(self::CHUNK_SIZE)->get();
            foreach ($rows as $row) {
                $payload = json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR);
                $status = 'imported';
                $errors = [];
                $productId = null;
                try {
                    if ($duplicate = $this->duplicate($payload)) {
                        $status = 'skipped';
                        $errors = [$duplicate];
                    } else {
                        $data = $this->writer->validate(Request::create('/api/products', 'POST', $payload));
                        $productId = $this->writer->create($data, $userId);
                    }
                } catch (ValidationException $error) {
                    $status = 'failed';
                    $errors = collect($error->errors())->flatten()->all();
                } catch (HttpExceptionInterface $error) {
                    if ($error->getStatusCode() !== 422) {
                        throw $error;
                    }
                    $status = 'failed';
                    $errors = [$error->getMessage()];
                }
                DB::table('product_import_rows')->where('id', $row->id)->update(['status' => $status,
                    'product_id' => $productId, 'errors' => json_encode($errors, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
            }
            $pending = DB::table('product_import_rows')->where('import_id', $id)->where('status', 'ready')->exists();
            DB::table('product_imports')->where('id', $id)->update(['status' => $pending ? 'processing' : 'completed', 'updated_at' => now()]);
        }, 3);

        return $this->summary($id);
    }

    private function duplicate(array $payload): ?string
    {
        foreach (['barcode', 'sku'] as $field) {
            if (($payload[$field] ?? '') !== '' && DB::table('products')->where('is_active', true)->where($field, $payload[$field])->exists()) {
                return 'An active product already uses this '.$field.'. Existing products are kept unchanged.';
            }
        }

        return null;
    }

    private function payload(array $row, array $types): array
    {
        $get = function (array $keys, $default = '') use ($row) {
            foreach ($keys as $key) {
                if (isset($row[$key]) && $row[$key] !== '') {
                    return $row[$key];
                }
            }

            return $default;
        };
        $number = function (array $keys, $default = 0) use ($get) {
            $raw = $get($keys, (string) $default);
            // Accept correctly grouped thousands; reject malformed or non-numeric input.
            if (str_contains($raw, ',') && preg_match('/^-?\d{1,3}(,\d{3})+(\.\d+)?$/D', $raw)) {
                $raw = str_replace(',', '', $raw);
            }

            return is_numeric($raw) && is_finite((float) $raw) ? $raw + 0 : $raw;
        };
        $prices = [];
        foreach ($types as $type) {
            $key = mb_strtolower($type);
            if (array_key_exists($key, $row) || array_key_exists($key.' price', $row)) {
                $prices[] = ['name' => $type, 'price' => $number([$key, $key.' price']), 'is_manual' => true];
            }
        }
        if (! $prices) {
            $prices[] = ['name' => 'Retail', 'price' => $number(['price']), 'is_manual' => true];
        }
        $retail = collect($prices)->first(fn ($price) => strcasecmp($price['name'], 'Retail') === 0) ?? $prices[0];

        return ['name' => $get(['name']), 'sku' => $get(['sku']), 'barcode' => $get(['barcode']),
            'category' => $get(['category']), 'base_unit' => $get(['base unit', 'base_unit'], 'Unit'),
            'purchase_unit' => $get(['purchase unit', 'purchase_unit']) ?: null,
            'purchase_conversion_factor' => $number(['units per purchase unit', 'purchase_conversion_factor'], 1),
            'cost' => $number(['cost']), 'stock' => $number(['stock']),
            'low_stock_threshold' => $number(['low stock alert', 'low_stock_alert', 'low_stock_threshold']),
            'price' => $retail['price'], 'prices' => $prices];
    }

    private function fileError(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}
