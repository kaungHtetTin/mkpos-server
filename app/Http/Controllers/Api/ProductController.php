<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProductController extends ApiController
{
    public function index(Request $request)
    {
        $query = DB::table('products');
        if (! $request->boolean('include_inactive')) {
            $query->where('is_active', true);
        }
        if ($search = trim((string) $request->query('q', ''))) {
            $query->where(function ($q) use ($search) {
                $like = "%{$search}%";
                $q->where('name', 'like', $like)->orWhere('sku', 'like', $like)
                    ->orWhere('barcode', 'like', $like)->orWhere('category', 'like', $like);
            });
        }
        if ($category = trim((string) $request->query('category', ''))) {
            $query->where('category', $category);
        }
        if ($barcode = trim((string) $request->query('barcode_q', ''))) {
            $query->where(fn ($q) => $q->where('barcode', 'like', "%{$barcode}%")->orWhere('sku', 'like', "%{$barcode}%"));
        }
        switch ($request->query('stock_status', 'all')) {
            case 'out_of_stock': $query->where('stock', '<=', 0);
            break;
            case 'low_stock': $query->where('stock', '>', 0)->where('low_stock_threshold', '>', 0)->whereColumn('stock', '<=', 'low_stock_threshold');
            break;
            case 'in_stock':
                $query->where('stock', '>', 0)->where(fn ($q) => $q->where('low_stock_threshold', '<=', 0)->orWhereColumn('stock', '>', 'low_stock_threshold'));
                break;
        }
        $result = $this->page($query->orderBy('name'), $request, 500, 500, true);
        if (isset($result['items'])) {
            $result['items'] = $this->attachPrices($result['items']);
        } else {
            $result = $this->attachPrices($result);
        }

        return $result;
    }

    public function categories()
    {
        return DB::table('products')->where('is_active', true)->where('category', '<>', '')
            ->distinct()->orderBy('category')->pluck('category')->all();
    }

    public function lowStock()
    {
        return $this->attachPrices(DB::table('products')->where('is_active', true)
            ->where('low_stock_threshold', '>', 0)->whereColumn('stock', '<=', 'low_stock_threshold')
            ->orderBy('stock')->orderBy('name')->get()->map(fn ($row) => (array) $row)->all());
    }

    public function summary()
    {
        $products = DB::table('products')->where('is_active', true);

        return ['total_products' => (clone $products)->count(), 'total_stock_units' => (float) ((clone $products)->sum('stock') ?: 0),
            'low_stock' => (clone $products)->where('stock', '>', 0)->where('low_stock_threshold', '>', 0)->whereColumn('stock', '<=', 'low_stock_threshold')->count(),
            'out_of_stock' => (clone $products)->where('stock', '<=', 0)->count()];
    }

    public function barcode(string $barcode)
    {
        $product = DB::table('products')->where('is_active', true)->where('barcode', trim($barcode))->where('barcode', '<>', '')->first();
        abort_if(! $product, 404, "No product found for barcode {$barcode}");

        return $this->attachPrices([(array) $product])[0];
    }

    public function show(int $id)
    {
        return $this->find($id);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        return DB::transaction(function () use ($data) {
            $now = now();
            $id = DB::table('products')->insertGetId(['name' => $data['name'], 'sku' => $data['sku'] ?? '', 'barcode' => trim($data['barcode'] ?? ''),
                'category' => $data['category'] ?? '', 'base_unit' => $data['base_unit'], 'purchase_unit' => $data['purchase_unit'],
                'purchase_conversion_factor' => $data['purchase_conversion_factor'], 'price' => $data['price'], 'cost' => $data['cost'] ?? 0,
                'base_cost' => $data['cost'] ?? 0, 'stock' => $data['stock'] ?? 0, 'low_stock_threshold' => $data['low_stock_threshold'] ?? 0,
                'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
            $this->savePrices($id, $data['prices'] ?? [], $data['price']);
            if (($data['stock'] ?? 0) != 0) {
                $this->insertMovement($id, $data['name'], 'opening_stock', (float) $data['stock'], 'product', $id, 'Initial product stock');
            }

            return $this->find($id);
        });
    }

    public function update(Request $request, int $id)
    {
        abort_if(! DB::table('products')->where('id', $id)->exists(), 404, 'Product not found');
        $data = $this->validated($request, $id, true);

        return DB::transaction(function () use ($data, $id) {
            $old = DB::table('products')->where('id', $id)->lockForUpdate()->first();
            DB::table('products')->where('id', $id)->update(['name' => $data['name'], 'sku' => $data['sku'] ?? '', 'barcode' => trim($data['barcode'] ?? ''),
                'category' => $data['category'] ?? '', 'base_unit' => $data['base_unit'], 'purchase_unit' => $data['purchase_unit'],
                'purchase_conversion_factor' => $data['purchase_conversion_factor'], 'price' => $data['price'], 'cost' => $data['cost'] ?? 0, 'base_cost' => $data['cost'] ?? 0,
                'stock' => $data['stock'] ?? 0, 'low_stock_threshold' => $data['low_stock_threshold'] ?? 0,
                'is_active' => $data['is_active'] ?? true, 'updated_at' => now()]);
            $this->savePrices($id, $data['prices'] ?? [], $data['price']);
            $change = (float) ($data['stock'] ?? 0) - (float) $old->stock;
            if ($change != 0) {
                $this->insertMovement($id, $data['name'], 'manual_adjustment', $change, 'product', $id, 'Product stock edited');
            }

            return $this->find($id);
        });
    }

    public function destroy(int $id)
    {
        abort_if(! DB::table('products')->where('id', $id)->where('is_active', true)->update(['is_active' => false, 'updated_at' => now()]), 404, 'Product not found');

        return ['ok' => true];
    }

    public function adjustStock(Request $request, int $id)
    {
        $data = $request->validate(['quantity' => ['required', 'numeric'], 'reason' => ['nullable', 'string']]);

        return DB::transaction(function () use ($data, $id) {
            $product = DB::table('products')->where('id', $id)->lockForUpdate()->first();
            abort_if(! $product, 404, 'Product not found');
            DB::table('products')->where('id', $id)->increment('stock', (float) $data['quantity'], ['updated_at' => now()]);
            $this->insertMovement($id, $product->name, 'manual_adjustment', (float) $data['quantity'], 'product', $id, $data['reason'] ?? '');

            return $this->find($id);
        });
    }

    public function movements(Request $request, int $id)
    {
        abort_if(! DB::table('products')->where('id', $id)->exists(), 404, 'Product not found');

        $query = DB::table('stock_movements')->where('product_id', $id)->orderByDesc('created_at')->orderByDesc('id');
        $limit = max(1, min((int) $request->query('limit', 200), 500));
        $offset = max((int) $request->query('offset', 0), 0);
        $total = (clone $query)->count();
        $items = $query->limit($limit)->offset($offset)->get();

        return $request->boolean('with_total')
            ? ['items' => $items, 'total' => $total, 'limit' => $limit, 'offset' => $offset]
            : $items;
    }

    private function validated(Request $request, ?int $id = null, bool $update = false): array
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'sku' => ['nullable', 'string', 'max:255'],
            'barcode' => ['nullable', 'string', 'max:255', Rule::unique('products', 'barcode')->ignore($id)->where(fn ($q) => $q->where('is_active', true))],
            'category' => ['nullable', 'string', 'max:255'], 'base_unit' => ['nullable', 'string', 'max:50'],
            'purchase_unit' => ['nullable', 'string', 'max:50'], 'purchase_conversion_factor' => ['nullable', 'numeric', 'min:1', 'max:1000000'],
            'price' => ['required', 'integer', 'min:0'], 'cost' => ['nullable', 'integer', 'min:0'],
            'stock' => ['nullable', 'numeric'], 'low_stock_threshold' => ['nullable', 'numeric', 'min:0'],
            'is_active' => [$update ? 'sometimes' : 'nullable', 'boolean'], 'prices' => ['nullable', 'array'],
            'prices.*.name' => ['required', 'string', 'max:50'], 'prices.*.price' => ['required', 'integer', 'min:0'], 'prices.*.is_manual' => ['nullable', 'boolean']]);

        $data['base_unit'] = trim((string) ($data['base_unit'] ?? '')) ?: 'Unit';
        $data['purchase_unit'] = trim((string) ($data['purchase_unit'] ?? '')) ?: null;
        $data['purchase_conversion_factor'] = $data['purchase_unit'] ? round((float) ($data['purchase_conversion_factor'] ?? 0), 3) : 1;
        abort_if($data['purchase_unit'] && strcasecmp($data['purchase_unit'], $data['base_unit']) === 0, 422, 'Purchase unit must be different from the base unit.');
        abort_if($data['purchase_unit'] && $data['purchase_conversion_factor'] <= 1, 422, 'Purchase conversion must be greater than one.');

        return $data;
    }

    private function savePrices(int $productId, array $prices, int $fallback): void
    {
        if (! $prices) {
            $prices = [['name' => 'Retail', 'price' => $fallback, 'is_manual' => true]];
        }
        DB::table('product_prices')->where('product_id', $productId)->delete();
        foreach ($prices as $price) {
            DB::table('product_prices')->insert(['product_id' => $productId, 'name' => trim($price['name']), 'price' => $price['price'], 'is_manual' => $price['is_manual'] ?? true]);
        }
        $primary = collect($prices)->first(fn ($price) => strcasecmp($price['name'], 'Retail') === 0) ?? $prices[0];
        DB::table('products')->where('id', $productId)->update(['price' => $primary['price']]);
    }

    private function attachPrices(array $products): array
    {
        if (! $products) {
            return $products;
        }
        $productIds = array_column($products, 'id');
        $prices = DB::table('product_prices')->whereIn('product_id', $productIds)->orderBy('name')->get()->groupBy('product_id');
        foreach ($products as &$product) {
            unset($product['active_barcode']);
            $product['stock'] = (float) $product['stock'];
            $product['low_stock_threshold'] = (float) $product['low_stock_threshold'];
            $product['purchase_conversion_factor'] = (float) $product['purchase_conversion_factor'];
            $product['is_active'] = (bool) $product['is_active'];
            $product['prices'] = isset($prices[$product['id']]) ? $prices[$product['id']]->map(fn ($row) => ['name' => $row->name, 'price' => (int) $row->price, 'is_manual' => (bool) $row->is_manual])->all()
                : [['name' => 'Retail', 'price' => (int) $product['price'], 'is_manual' => true]];
        }

        return $products;
    }

    private function find(int $id): array
    {
        $product = DB::table('products')->where('id', $id)->first();
        abort_if(! $product, 404, 'Product not found');

        return $this->attachPrices([(array) $product])[0];
    }
}
