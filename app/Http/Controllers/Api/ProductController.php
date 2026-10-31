<?php

namespace App\Http\Controllers\Api;

use App\Services\EcommerceInventory;
use App\Services\MarketplaceTaxonomy;
use App\Services\MerchantMarketplaceCategory;
use App\Services\ProductWriter;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ProductController extends ApiController
{
    public function index(Request $request)
    {
        $query = DB::table('products');
        if (! $request->boolean('include_inactive')) {
            $query->where('is_active', true);
        }
        if ($ids = trim((string) $request->query('ids', ''))) {
            $productIds = collect(explode(',', $ids))
                ->map(fn ($id) => (int) trim($id))
                ->filter(fn ($id) => $id > 0)
                ->unique()
                ->values()
                ->all();
            $productIds ? $query->whereIn('id', $productIds) : $query->whereRaw('1 = 0');
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
        if ($request->boolean('has_barcode')) {
            $query->whereNotNull('barcode')->where('barcode', '<>', '');
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
        $direction = $request->query('sort') === 'name_desc' ? 'desc' : 'asc';
        $result = $this->page($query->orderBy('name', $direction)->orderBy('id', $direction), $request, 500, 500, true);
        if (isset($result['items'])) {
            $result['items'] = $this->attachPrices($result['items']);
        } else {
            $result = $this->attachPrices($result);
        }

        return $result;
    }

    public function marketplaceCategories(): array
    {
        return ['items' => app(MarketplaceTaxonomy::class)->categories()->filter(fn ($category) => $category['effective_active'])->sortBy('path')->values()];
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

    public function internalBarcode()
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $body = '20'.str_pad((string) random_int(0, 9999999999), 10, '0', STR_PAD_LEFT);
            $sum = 0;
            foreach (str_split($body) as $index => $digit) {
                $sum += (int) $digit * ($index % 2 === 0 ? 1 : 3);
            }
            $barcode = $body.((10 - ($sum % 10)) % 10);
            if (! DB::table('products')->where('barcode', $barcode)->where('is_active', true)->exists()) {
                return ['barcode' => $barcode];
            }
        }

        abort(503, 'Unable to generate a unique internal barcode. Please try again.');
    }

    public function show(int $id)
    {
        return $this->find($id);
    }

    public function photo(int $id)
    {
        $product = DB::table('products')->where('id', $id)->first(['photo_path']);
        abort_if(! $product, 404, 'Product not found');
        abort_unless($product->photo_path && Storage::disk('local')->exists($product->photo_path), 404, 'Product photo not found');

        return response()->file(Storage::disk('local')->path($product->photo_path), [
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function uploadPhoto(Request $request, int $id)
    {
        $product = DB::table('products')->where('id', $id)->first(['id', 'business_id', 'photo_path']);
        abort_if(! $product, 404, 'Product not found');
        $data = $request->validate([
            'photo' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:50', 'dimensions:ratio=1/1'],
        ]);
        $path = $data['photo']->store('product-photos/'.$product->business_id, 'local');

        try {
            DB::table('products')->where('id', $id)->update(['photo_path' => $path, 'updated_at' => now()]);
        } catch (\Throwable $error) {
            Storage::disk('local')->delete($path);
            throw $error;
        }

        if ($product->photo_path && $product->photo_path !== $path) {
            Storage::disk('local')->delete($product->photo_path);
        }

        return $this->find($id);
    }

    public function deletePhoto(int $id)
    {
        $product = DB::table('products')->where('id', $id)->first(['id', 'photo_path']);
        abort_if(! $product, 404, 'Product not found');
        DB::table('products')->where('id', $id)->update(['photo_path' => null, 'updated_at' => now()]);
        if ($product->photo_path) {
            Storage::disk('local')->delete($product->photo_path);
        }

        return $this->find($id);
    }

    public function store(Request $request)
    {
        $writer = app(ProductWriter::class);
        $data = $writer->validate($request);

        return DB::transaction(fn () => $this->find($writer->create($data, (int) $request->user('web')->id)));
    }

    public function update(Request $request, int $id)
    {
        abort_if(! DB::table('products')->where('id', $id)->exists(), 404, 'Product not found');
        $data = app(ProductWriter::class)->validate($request, $id, true);

        return DB::transaction(function () use ($data, $id, $request) {
            $current = DB::table('products')->where('id', $id)->lockForUpdate()->first();
            abort_unless($current, 404, 'Product not found');
            $sensitive = false;
            foreach (['stock', 'cost', 'price', 'is_active'] as $field) {
                if ((float) ($data[$field] ?? ($field === 'is_active' ? $current->$field : 0)) !== (float) $current->$field) $sensitive = true;
            }
            $prices = fn ($rows) => collect($rows)->mapWithKeys(fn ($row) => [trim((string) data_get($row, 'name')) => [(int) data_get($row, 'price'), (bool) data_get($row, 'is_manual', true)]])->sortKeys()->all();
            $nextPrices = $data['prices'] ?? [];
            if (! $nextPrices) $nextPrices = [['name' => 'Retail', 'price' => $data['price'], 'is_manual' => true]];
            $sensitive = $sensitive || $prices($nextPrices) !== $prices(DB::table('product_prices')->where('product_id', $id)->get());
            if ($sensitive) app(\App\Services\AdminPinProtection::class)->authorize($request);
            app(ProductWriter::class)->update($id, $data, (int) $request->user('web')->id);

            return $this->find($id);
        });
    }

    public function destroyMany(Request $request): array
    {
        app(\App\Services\AdminPinProtection::class)->authorize($request);
        $data = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:1000'],
            'ids.*' => ['required', 'integer', 'min:1', 'distinct']]);
        $businessId = app(TenantContext::class)->requireId();

        return DB::transaction(function () use ($data, $businessId) {
            $products = DB::table('products')->where('business_id', $businessId)->whereIn('id', $data['ids'])
                ->where('is_active', true)->orderBy('id')->lockForUpdate()->get();
            if ($products->count() !== count($data['ids'])) {
                throw ValidationException::withMessages(['ids' => 'Some selected products are no longer available. Refresh the list and select them again.']);
            }
            foreach ($products as $product) {
                abort_if(app(EcommerceInventory::class)->reserved($product->id, $businessId) > 0, 409,
                    "Resolve online orders for {$product->name} before deleting these products.");
            }
            DB::table('products')->where('business_id', $businessId)->whereIn('id', $data['ids'])
                ->update(['is_active' => false, 'updated_at' => now()]);

            return ['ok' => true, 'deleted_count' => $products->count()];
        });
    }

    public function destroy(int $id)
    {
        app(\App\Services\AdminPinProtection::class)->authorize();
        return DB::transaction(function () use ($id) {
            $product = DB::table('products')->where('id', $id)->where('is_active', true)->lockForUpdate()->first();
            abort_unless($product, 404, 'Product not found');
            abort_if(app(EcommerceInventory::class)->reserved($id, $product->business_id) > 0, 409, 'Resolve online orders before archiving this product.');
            DB::table('products')->where('id', $id)->update(['is_active' => false, 'updated_at' => now()]);

            return ['ok' => true];
        });
    }

    public function adjustStock(Request $request, int $id)
    {
        app(\App\Services\AdminPinProtection::class)->authorize($request);
        $data = $request->validate(['quantity' => ['required', 'numeric'], 'reason' => ['nullable', 'string']]);

        return DB::transaction(function () use ($data, $id) {
            $product = DB::table('products')->where('id', $id)->lockForUpdate()->first();
            abort_if(! $product, 404, 'Product not found');
            app(EcommerceInventory::class)->assertRemaining($product, (float) $product->stock + (float) $data['quantity']);
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

    private function attachPrices(array $products): array
    {
        if (! $products) {
            return $products;
        }
        $productIds = array_column($products, 'id');
        $prices = DB::table('product_prices')->whereIn('product_id', $productIds)->orderBy('name')->get()->groupBy('product_id');
        $descriptions = DB::table('ecommerce_product_settings')
            ->where('business_id', app(TenantContext::class)->requireId())
            ->whereIn('product_id', $productIds)->pluck('description', 'product_id');
        $reserved = DB::table('ecommerce_stock_reservations')->where('business_id', app(TenantContext::class)->requireId())
            ->whereIn('product_id', $productIds)->where('status', 'active')->select('product_id')->selectRaw('SUM(quantity) as quantity')->groupBy('product_id')->pluck('quantity', 'product_id');
        $dispatched = DB::table('ecommerce_stock_reservations as r')->join('ecommerce_orders as o', 'o.id', '=', 'r.order_id')
            ->where('r.business_id', app(TenantContext::class)->requireId())->whereIn('r.product_id', $productIds)
            ->where('r.status', 'active')->whereIn('o.status', ['dispatched', 'return_pending'])->select('r.product_id')->selectRaw('SUM(r.quantity) as quantity')->groupBy('r.product_id')->pluck('quantity', 'r.product_id');
        foreach ($products as &$product) {
            unset($product['active_barcode']);
            $product['online_description'] = $descriptions[$product['id']] ?? '';
            $photoPath = $product['photo_path'] ?? null;
            unset($product['photo_path']);
            $product['photo_url'] = $photoPath ? '/products/'.$product['id'].'/photo?v='.substr(hash('sha256', $photoPath), 0, 16) : null;
            $product['stock'] = (float) $product['stock'];
            $product['reserved_stock'] = (float) ($reserved[$product['id']] ?? 0);
            $product['dispatched_stock'] = (float) ($dispatched[$product['id']] ?? 0);
            $product['available_stock'] = $product['stock'] - $product['reserved_stock'];
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

        return $this->attachPrices([(array) $product])[0] + app(MerchantMarketplaceCategory::class)->details($id, (int) $product->business_id);
    }
}
