<?php

namespace App\Services;

use App\Tenancy\TenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Existing record proposals and the full management registry; no arbitrary table, SQL or URL. */
class AiBusinessOperations
{
    public const OPERATIONS = ['create_product', 'update_product', 'update_product_prices', 'execute_product_import',
        'create_purchase_draft', 'manage_app_settings', 'create_customer', 'update_customer', 'create_supplier', 'update_supplier', 'create_expense', 'update_expense'];

    public const PRODUCT_FIELDS = ['name', 'sku', 'barcode', 'category', 'base_unit', 'purchase_unit', 'purchase_conversion_factor', 'cost', 'low_stock_threshold'];

    public function table(string $table): Builder
    {
        return DB::table($table)->where($table.'.business_id', app(TenantContext::class)->requireId());
    }

    public function module(string $operation): string
    {
        if (app(AiManagementOperations::class)->supports($operation)) {
            return app(AiManagementOperations::class)->module($operation);
        }

        return match (true) {
            $operation === 'manage_app_settings' => 'settings',
            $operation === 'create_purchase_draft' => 'purchases',
            str_contains($operation, 'product') => 'products',
            str_contains($operation, 'customer') => 'customers',
            str_contains($operation, 'supplier') => 'suppliers',
            default => 'expenses',
        };
    }

    public function build(string $operation, array $input, int $userId): array
    {
        if (app(AiManagementOperations::class)->supports($operation)) {
            return app(AiManagementOperations::class)->build($operation, $input, $userId);
        }
        abort_unless(in_array($operation, self::OPERATIONS, true), 422, 'Unsupported business action.');
        if ($operation === 'update_product_prices') {
            return $this->prices($input);
        }
        if ($operation === 'execute_product_import') {
            return $this->import($input, $userId);
        }
        if ($operation === 'manage_app_settings') {
            return app(AiAppSettings::class)->build($input);
        }
        if ($operation === 'create_purchase_draft') {
            return app(AiInvoicePurchases::class)->build($input, $userId);
        }
        $update = str_starts_with($operation, 'update_');
        $type = substr($operation, strpos($operation, '_') + 1);
        $fields = match ($type) {
            'product' => [...self::PRODUCT_FIELDS, ...($update ? [] : ['price', 'prices'])],
            'customer' => ['name', 'phone', 'email', 'address', 'note'],
            'supplier' => ['name', 'phone', 'address', 'contact_person', 'note'],
            'expense' => ['title', 'category', 'amount', 'payment_method', 'expense_date', 'note'],
        };
        $this->keys($input, [...$fields, ...($update ? ['record_id'] : [])]);
        validator($input, ['record_id' => $update ? 'required|integer|min:1' : 'prohibited', 'note' => 'sometimes|nullable|string|max:2000',
            'address' => 'sometimes|nullable|string|max:2000', 'prices' => 'sometimes|array|max:20', 'prices.*' => 'array:name,price,is_manual',
            'expense_date' => 'sometimes|date_format:Y-m-d'])->validate();
        $id = $update ? (int) $input['record_id'] : null;
        unset($input['record_id']);
        abort_unless($input, 422, 'Supply the fields to change.');
        $table = $type === 'expense' ? 'expenses' : $type.'s';
        $before = $id ? $this->snapshot($table, $id) : [];
        if ($id) {
            abort_if(($before['row']['is_active'] ?? true) == false || ($before['row']['status'] ?? '') === 'voided', 409, 'This record is no longer editable.');
        }
        $merged = array_replace($before['row'] ?? [], $input);
        if ($type === 'product') {
            if ($id) {
                $merged['prices'] = $before['prices'];
            }
            // New products start at zero stock. Stock-changing tools belong to Phase 3.
            if (! $id) {
                $merged['stock'] = 0;
                $merged += ['sku' => '', 'barcode' => '', 'category' => '', 'cost' => 0, 'low_stock_threshold' => 0];
            }
            $data = app(ProductWriter::class)->validate(Request::create('/', 'POST', $merged), $id, $update);
            if (! $id && empty($data['prices'])) {
                $data['prices'] = [['name' => 'Retail', 'price' => $data['price'], 'is_manual' => true]];
            }
            if (! empty($data['prices'])) {
                $primary = collect($data['prices'])->first(fn ($price) => strcasecmp(trim($price['name']), 'Retail') === 0) ?? $data['prices'][0];
                $data['price'] = $primary['price'];
            }
            $names = array_map(fn ($price) => mb_strtolower(trim($price['name'])), $data['prices'] ?? []);
            abort_if(count($names) !== count(array_unique($names)) || in_array('', $names, true), 422, 'Price names must be non-empty and unique.');
            if ($id) {
                app(EcommerceInventory::class)->assertEditable((object) $before['row'], $data);
            }
        } else {
            $data = app(BusinessRecordWriter::class)->validate($type, $merged);
        }
        $after = array_intersect_key($data, array_flip($id ? array_keys($input) : array_keys($data)));
        $visibleBefore = $id ? array_intersect_key($before['row'], $after) : null;
        abort_if($id && $visibleBefore == $after, 422, 'These values are already saved.');

        return ['payload' => ['type' => $type, 'record_id' => $id, 'data' => $data], 'baseline' => $before,
            'preview' => [['record_id' => $id, 'name' => $merged['name'] ?? $merged['title'], 'before' => $visibleBefore, 'after' => $after]]];
    }

    public function snapshot(string $table, int $id): array
    {
        $row = $this->table($table)->where('id', $id)->lockForUpdate()->first();
        abort_unless($row, 404, 'Record unavailable.');
        $result = ['row' => (array) $row];
        if ($table === 'products') {
            $result['prices'] = $this->table('product_prices')->where('product_id', $id)->orderBy('id')->lockForUpdate()->get(['name', 'price', 'is_manual'])->map(fn ($p) => (array) $p)->all();
        }

        return $result;
    }

    private function prices(array $input): array
    {
        $this->keys($input, ['changes', 'category', 'price_type', 'percentage']);
        validator($input, ['changes' => 'sometimes|array|min:1|max:100', 'changes.*' => 'array:product_id,price_type,price',
            'changes.*.product_id' => 'required|integer|min:1', 'changes.*.price_type' => 'required|string|max:50', 'changes.*.price' => 'required|integer|min:0|max:1000000000000',
            'category' => 'required_without:changes|string|max:255', 'price_type' => 'required_without:changes|string|max:50',
            'percentage' => 'required_without:changes|numeric|min:-100|max:1000'])->validate();
        abort_if(isset($input['changes']) && count($input) > 1, 422, 'Use explicit price changes or a category percentage, not both.');
        $changes = $input['changes'] ?? [];
        if (! $changes) {
            $ids = $this->table('products')->where('is_active', true)->where('category', $input['category'])->orderBy('id')->limit(101)->pluck('id');
            abort_if($ids->isEmpty() || $ids->count() > 100, 422, 'Select between 1 and 100 products; narrow the category if necessary.');
            foreach ($ids as $id) {
                $price = $this->table('product_prices')->where('product_id', $id)->where('name', $input['price_type'])->value('price');
                abort_if($price === null, 422, 'A product does not have the requested named price.');
                $changes[] = ['product_id' => (int) $id, 'price_type' => $input['price_type'], 'price' => (int) round($price * (1 + $input['percentage'] / 100))];
            }
        }
        usort($changes, fn ($a, $b) => $a['product_id'] <=> $b['product_id']);
        $baseline = $payload = $preview = $seen = [];
        foreach ($changes as $change) {
            validator($change, ['price' => 'required|integer|min:0|max:1000000000000'])->validate();
            $id = (int) $change['product_id'];
            $baseline[$id] ??= $this->snapshot('products', $id);
            abort_unless($baseline[$id]['row']['is_active'], 409, 'Product is inactive.');
            $payload[$id] ??= $baseline[$id]['prices'];
            $index = collect($payload[$id])->search(fn ($p) => strcasecmp($p['name'], $change['price_type']) === 0);
            abort_if($index === false, 422, 'Named price unavailable.');
            $key = $id.':'.$index;
            abort_if(isset($seen[$key]), 422, 'Supply each product and price type only once.');
            $seen[$key] = true;
            $preview[] = ['record_id' => $id, 'name' => $baseline[$id]['row']['name'], 'before' => ['price_type' => $payload[$id][$index]['name'], 'price' => $payload[$id][$index]['price'], 'is_manual' => $payload[$id][$index]['is_manual']],
                'after' => ['price_type' => $payload[$id][$index]['name'], 'price' => $change['price'], 'is_manual' => true]];
            $payload[$id][$index]['price'] = $change['price'];
            $payload[$id][$index]['is_manual'] = true;
        }

        return ['payload' => ['prices' => $payload], 'baseline' => $baseline, 'preview' => $preview];
    }

    private function import(array $input, int $userId): array
    {
        $this->keys($input, ['import_id']);
        validator($input, ['import_id' => 'required|uuid'])->validate();
        $import = $this->table('product_imports')->where('id', $input['import_id'])->where('user_id', $userId)->lockForUpdate()->first();
        abort_unless($import, 404, 'Import unavailable.');
        $rows = DB::table('product_import_rows')->where('import_id', $import->id)->orderBy('row_number')->lockForUpdate()->get();
        abort_if($rows->contains(fn ($r) => in_array($r->status, ['invalid', 'failed'])), 422, 'Fix invalid or failed rows before importing.');
        $ready = $rows->where('status', 'ready');
        abort_if($ready->isEmpty(), 422, 'This import has no ready products.');
        // Freeze all staging rows and price-type configuration; do not send raw CSV to the model.
        $baseline = ['rows' => $rows->map(fn ($r) => (array) $r)->all(), 'price_types' => $this->table('settings')->where('key', 'price_types')->value('value')];
        $preview = $ready->map(fn ($r) => ['row_number' => $r->row_number, 'name' => $r->name, 'before' => null,
            'after' => json_decode($r->payload, true, flags: JSON_THROW_ON_ERROR)])->values()->all();

        return ['payload' => ['import_id' => $import->id, 'ready_count' => $ready->count(), 'already_imported' => $rows->where('status', 'imported')->count()],
            'baseline' => $baseline, 'preview' => $preview,
            'import_summary' => app(ProductImportService::class)->summary($import->id)];
    }

    public function unchanged(string $operation, array $payload, array $baseline, int $userId): bool
    {
        if (app(AiManagementOperations::class)->supports($operation)) {
            return app(AiManagementOperations::class)->unchanged($operation, $payload, $baseline, $userId);
        }
        if ($operation === 'create_purchase_draft') {
            return app(AiInvoicePurchases::class)->build($payload['invoice_input'], $userId)['baseline'] == $baseline;
        }
        if ($operation === 'manage_app_settings') {
            return app(AiAppSettings::class)->baseline() == $baseline;
        }
        if ($operation === 'execute_product_import') {
            return $this->import(['import_id' => $payload['import_id']], $userId)['baseline'] == $baseline;
        }
        if ($operation === 'update_product_prices') {
            foreach ($baseline as $id => $snapshot) {
                if ($this->snapshot('products', (int) $id) != $snapshot) {
                    return false;
                }
            }

            return true;
        }
        if (! $payload['record_id']) {
            return true;
        }
        $table = $payload['type'] === 'expense' ? 'expenses' : $payload['type'].'s';

        return $this->snapshot($table, $payload['record_id']) == $baseline;
    }

    public function execute(string $operation, array $payload, int $userId, array $approval = []): array
    {
        if (app(AiManagementOperations::class)->supports($operation)) {
            return app(AiManagementOperations::class)->execute($operation, $payload, $userId, $approval);
        }
        if ($operation === 'create_purchase_draft') {
            return app(AiInvoicePurchases::class)->save($payload, $userId);
        }
        if ($operation === 'manage_app_settings') {
            return app(AiAppSettings::class)->execute($payload);
        }
        if ($operation === 'update_product_prices') {
            foreach ($payload['prices'] as $id => $prices) {
                app(ProductWriter::class)->savePrices((int) $id, $prices, $prices[0]['price']);
                $this->table('products')->where('id', $id)->update(['updated_at' => now()]);
            }

            return ['updated_products' => count($payload['prices']), 'modules' => ['products', 'reports', 'sell', 'shop']];
        }
        if ($operation === 'execute_product_import') {
            do {
                $summary = app(ProductImportService::class)->process($payload['import_id'], $userId);
            } while ($summary['status'] !== 'completed');
            abort_unless($summary['imported'] === $payload['already_imported'] + $payload['ready_count'] && ! $summary['failed'], 409, 'Import conditions changed. Nothing was imported; prepare a fresh preview.');

            return ['import' => $summary, 'modules' => ['products', 'reports', 'sell', 'shop']];
        }
        $id = $payload['record_id'];
        $type = $payload['type'];
        $data = $payload['data'];
        if ($type === 'product') {
            $data = app(ProductWriter::class)->validate(Request::create('/', 'POST', $data), $id, (bool) $id);
            if ($id) {
                app(ProductWriter::class)->update($id, $data, $userId);
            } else {
                $id = app(ProductWriter::class)->create($data, $userId);
            }
        } else {
            $data = app(BusinessRecordWriter::class)->validate($type, $data);
            $table = $type === 'expense' ? 'expenses' : $type.'s';
            if ($id) {
                $this->table($table)->where('id', $id)->update($data + ['updated_at' => now()]);
            } else {
                $id = DB::table($table)->insertGetId($data + ['business_id' => app(TenantContext::class)->requireId(),
                    ...($type === 'expense' ? ['status' => 'completed'] : ['is_active' => true]), 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        return ['record_id' => $id, 'type' => $type, 'modules' => match ($type) {
            'product' => ['products', 'reports', 'sell', 'shop'], 'customer' => ['customers'], 'supplier' => ['suppliers'], 'expense' => ['expenses', 'reports'],
        }];
    }

    private function keys(array $input, array $allowed): void
    {
        abort_if(array_diff(array_keys($input), $allowed), 422, 'Unsupported fields in business action.');
    }
}
