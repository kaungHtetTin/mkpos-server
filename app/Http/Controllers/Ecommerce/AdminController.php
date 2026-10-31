<?php

namespace App\Http\Controllers\Ecommerce;

use App\Http\Controllers\Controller;
use App\Services\EcommerceService;
use App\Services\ShopPaymentMethods;
use App\Services\BusinessProfile;
use App\Support\BusinessLogo;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class AdminController extends Controller
{
    public function __construct(private EcommerceService $commerce, private TenantContext $tenant) {}

    public function settings(): array
    {
        $id = $this->tenant->requireId();
        $b = DB::table('businesses')->where('id', $id)->first();
        DB::table('storefront_settings')->insertOrIgnore(['business_id' => $id, 'slug' => $b->slug.'-'.$id,
            'display_name' => $b->name, 'delivery_zones' => '[]',
            'payment_methods' => json_encode([ShopPaymentMethods::defaultCash()]),
            'created_at' => now(), 'updated_at' => now()]);
        $row = (array) DB::table('storefront_settings')->where('business_id', $id)->first();
        $profile = app(BusinessProfile::class)->read($id);
        $row['display_name'] = $profile['shop_name'];
        $row['phone'] = $profile['shop_phone'];
        $row['address'] = $profile['shop_address'];
        $row['currency'] = $profile['currency'];
        $row['receipt_logo_data_url'] = BusinessLogo::dataUrl((string) DB::table('settings')->where('key', BusinessLogo::SETTING_KEY)->value('value'));
        $row['cover_data_url'] = BusinessLogo::dataUrl((string) DB::table('settings')->where('business_id', $id)->where('key', ShopCoverController::SETTING_KEY)->value('value'));
        $row['delivery_zones'] = json_decode($row['delivery_zones'], true);
        $row['payment_methods'] = json_decode($row['payment_methods'], true);
        $row['opening_hours'] = json_decode($row['opening_hours'] ?? 'null', true) ?: [];
        $row['timezone'] = $b->timezone ?: 'Asia/Yangon';
        $row['closed_until'] = !empty($row['closed_until']) ? CarbonImmutable::parse($row['closed_until'], 'UTC')->toIso8601String() : null;
        $row['slug_locked'] = DB::table('ecommerce_orders')->where('business_id', $id)->exists();
        $location = DB::table('shop_owner_locations')->where('business_id', $id)->first();
        $row['location'] = $location ? ['latitude' => (float) $location->latitude, 'longitude' => (float) $location->longitude] : null;
        $row['price_types'] = DB::table('price_type_rules')->where('business_id', $id)->orderBy('name')->pluck('name')->all();
        return $row;
    }

    public function updateSettings(Request $request): array
    {
        $id = $this->tenant->requireId();
        $current = $this->settings();
        $data = $request->validate([
            'slug' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('storefront_settings')->ignore($current['id'])],
            'display_name' => ['sometimes', 'required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:4000'], 'phone' => ['sometimes', 'nullable', 'string'],
            'address' => ['sometimes', 'nullable', 'string'], 'currency' => ['sometimes', 'required', 'string', 'max:20'],
            'storefront_enabled' => ['required', 'boolean'], 'accepting_orders' => ['required', 'boolean'], 'marketplace_enabled' => ['required', 'boolean'],
            'price_type' => ['required', 'string', 'max:50', Rule::exists('price_type_rules', 'name')->where('business_id', $id)],
            'pickup_instructions' => ['nullable', 'string', 'max:2000'],
            'pickup_address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'pickup_hours' => ['sometimes', 'nullable', 'string', 'max:255'],
            'announcement' => ['sometimes', 'nullable', 'string', 'max:1000'], 'closed_until' => ['sometimes', 'nullable', 'date'],
            'opening_hours' => ['sometimes', 'array', 'max:7'], 'opening_hours.*.closed' => ['required', 'boolean'],
            'opening_hours.*.opens' => ['required', 'date_format:H:i'], 'opening_hours.*.closes' => ['required', 'date_format:H:i'],
            'delivery_zones' => ['present', 'array', 'max:100'], 'delivery_zones.*.id' => ['required', 'string', 'max:50', 'distinct', Rule::notIn(['__delivery_service__'])],
            'delivery_zones.*.name' => ['required', 'string', 'max:255'], 'delivery_zones.*.fee' => ['required', 'integer', 'min:0', 'max:1000000000'],
            'payment_methods' => ['sometimes', 'array', 'min:1', 'max:20'], 'payment_methods.*.id' => ['required', 'string', 'max:50', 'distinct'],
        ] + ShopPaymentMethods::rules('payment_methods.*.'));
        if (isset($data['opening_hours'])) {
            abort_unless(in_array(count($data['opening_hours']), [0, 7]), 422, 'Provide all seven days of opening hours.');
            foreach ($data['opening_hours'] as $day) abort_if(!$day['closed'] && $day['opens'] >= $day['closes'], 422, 'Closing time must be after opening time.');
            $data['opening_hours'] = json_encode(array_map(fn ($day) => array_intersect_key($day, array_flip(['closed', 'opens', 'closes'])), array_values($data['opening_hours'])));
        }
        if (!empty($data['closed_until'])) $data['closed_until'] = CarbonImmutable::parse($data['closed_until'], $current['timezone'])->utc()->toDateTimeString();
        if (array_key_exists('phone', $data)) $data['phone'] = $data['phone'] ?? '';
        $profile = [];
        if ($request->user('web')?->role === 'owner') {
            foreach (['display_name' => 'shop_name', 'phone' => 'shop_phone', 'address' => 'shop_address', 'currency' => 'currency'] as $field => $key) {
                if (array_key_exists($field, $data)) $profile[$key] = $data[$field];
            }
        }
        unset($data['address'], $data['currency']);
        if ($request->user('web')?->role !== 'owner') unset($data['display_name'], $data['phone']);
        // Whitelist nested public configuration fields as well as root fields.
        $data['delivery_zones'] = json_encode(array_map(fn ($v) => array_intersect_key($v, array_flip(['id', 'name', 'fee'])), $data['delivery_zones']));
        if (isset($data['payment_methods'])) {
            $methods = array_map([ShopPaymentMethods::class, 'normalize'], $data['payment_methods']);
            ShopPaymentMethods::validateAvailability($methods);
            $data['payment_methods'] = json_encode($methods);
        }
        DB::transaction(function () use ($id, $data, $profile) {
            $store = DB::table('storefront_settings')->where('business_id', $id)->lockForUpdate()->first();
            abort_if($store->slug !== $data['slug'] && DB::table('ecommerce_orders')->where('business_id', $id)->exists(), 409,
                'The shop slug cannot change after receiving orders. This preserves customer links and checkout retries.');
            DB::table('storefront_settings')->where('business_id', $id)->update($data + ['updated_at' => now()]);
            app(BusinessProfile::class)->update($id, $profile);
        });
        return $this->settings();
    }

    public function publicationFilters(): array
    {
        return ['categories' => DB::table('products')->where('business_id', $this->tenant->requireId())->where('is_active', true)
            ->whereNotNull('category')->where('category', '<>', '')->distinct()->orderBy('category')->pluck('category')->all()];
    }

    public function publication(int $id): array
    {
        $rows = $this->publications(new Request(['limit' => 1]), $id)['items'];
        abort_unless($rows->isNotEmpty(), 404, 'Product unavailable.');
        return (array) $rows->first();
    }

    public function publications(Request $request, ?int $productId = null): array
    {
        $id = $this->tenant->requireId();
        $priceType = DB::table('storefront_settings')->where('business_id', $id)->value('price_type') ?: 'Retail';
        $currency = DB::table('businesses')->where('id', $id)->value('currency');
        $limit = max(1, min(100, (int) $request->query('limit', 30)));
        $query = $this->publicationQuery($request);
        $picker = $request->validate([
            'flash_sale_picker' => ['sometimes', 'boolean'], 'flash_sale_enabled' => ['sometimes', 'boolean'],
            'flash_sale_starts_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'flash_sale_ends_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'flash_sale_exclude_id' => ['nullable', 'integer', 'min:1'],
            'flash_sale_product_ids' => ['sometimes', 'array', 'max:100'],
            'flash_sale_product_ids.*' => ['integer', 'min:1', 'distinct'],
        ]);
        if (!empty($picker['flash_sale_picker']) && isset($picker['flash_sale_product_ids'])) {
            $query->whereIn('p.id', $picker['flash_sale_product_ids']);
        }
        if ($productId !== null) $query->where('p.id', $productId);
        $total = (clone $query)->count();
        $publishedCount = (clone $query)->where('ep.published', true)->count();
        $rows = $query->select('p.id', 'p.name', 'p.photo_path', 'p.updated_at', 'p.category', 'p.stock', 'p.base_unit', 'p.purchase_unit', 'p.purchase_conversion_factor', 'pp.price as base_price', 'ep.published', 'ep.unit', 'ep.description')
            ->orderBy('p.id')->offset(max(0, (int) $request->query('offset', 0)))->limit($limit + 1)->get();
        $conflicts = collect();
        if (!empty($picker['flash_sale_picker']) && ($picker['flash_sale_enabled'] ?? true)) {
            $timezone = DB::table('businesses')->where('id', $id)->value('timezone') ?: 'Asia/Rangoon';
            $start = empty($picker['flash_sale_starts_at']) ? null : CarbonImmutable::createFromFormat('Y-m-d\TH:i', $picker['flash_sale_starts_at'], $timezone)->second(0)->utc();
            $end = empty($picker['flash_sale_ends_at']) ? null : CarbonImmutable::createFromFormat('Y-m-d\TH:i', $picker['flash_sale_ends_at'], $timezone)->second(0)->utc();
            if ($start && $end && $end <= $start) throw \Illuminate\Validation\ValidationException::withMessages(['flash_sale_ends_at' => 'End time must be later than start time.']);
            $conflicts = DB::table('shop_promotions')->where('business_id', $id)->where('type', 'flash_sale')
                ->whereIn('product_id', $rows->pluck('id'))->whereNull('deleted_at')->where('enabled', true)
                ->when($picker['flash_sale_exclude_id'] ?? null, fn ($q, $exclude) => $q->where('id', '<>', $exclude))
                ->when($end, fn ($q, $end) => $q->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<', $end)))
                ->when($start, fn ($q, $start) => $q->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $start)))
                ->orderByDesc('id')->get(['id', 'product_id', 'name'])->unique('product_id')->keyBy('product_id');
        }
        foreach ($rows as $row) {
            $row->photo_url = $row->photo_path ? '/shop-admin/publications/'.$row->id.'/photo?v='.substr(hash('sha256', $row->photo_path), 0, 16) : null;
            unset($row->photo_path, $row->updated_at);
            $row->stock = (float) $row->stock;
            $row->base_price = $row->base_price === null ? null : (int) $row->base_price;
            $row->purchase_price = $row->base_price !== null && $row->purchase_unit && $row->purchase_conversion_factor > 0
                ? intdiv($row->base_price * $this->commerce->milli($row->purchase_conversion_factor) + 500, 1000) : null;
            $row->price_type = $priceType;
            $row->currency = $currency;
            if (!empty($picker['flash_sale_picker'])) {
                $conflict = $conflicts->get($row->id);
                $row->flash_sale_conflict = $conflict ? ['id' => $conflict->id, 'name' => $conflict->name] : null;
            }
        }
        return ['items' => $rows->take($limit)->values(), 'has_more' => $rows->count() > $limit, 'total' => $total, 'published_count' => $publishedCount];
    }

    public function publicationPhoto(int $id)
    {
        $product = DB::table('products')->where('business_id', $this->tenant->requireId())->where('id', $id)->first(['photo_path']);
        abort_unless($product && $product->photo_path && Storage::disk('local')->exists($product->photo_path), 404, 'Product photo not found');

        return response()->file(Storage::disk('local')->path($product->photo_path), [
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function publicationQuery(Request $request)
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:255'], 'category' => ['nullable', 'string', 'max:255'],
            'stock_status' => ['nullable', 'in:all,in_stock,low_stock,out_of_stock'],
            'publication_status' => ['nullable', 'in:all,published,unpublished'],
            'price_status' => ['nullable', 'in:all,priced,missing'],
            'sort' => ['nullable', 'in:name_asc,name_desc,low_stock_first,stock_asc,stock_desc,price_asc,price_desc,newest,oldest'],
        ]);
        $id = $this->tenant->requireId();
        $priceType = DB::table('storefront_settings')->where('business_id', $id)->value('price_type') ?: 'Retail';
        $query = DB::table('products as p')->leftJoin('ecommerce_product_settings as ep', function ($j) {
            $j->on('ep.product_id', '=', 'p.id')->on('ep.business_id', '=', 'p.business_id');
        })->leftJoin('product_prices as pp', function ($join) use ($priceType) {
            $join->on('pp.product_id', '=', 'p.id')->on('pp.business_id', '=', 'p.business_id')->where('pp.name', $priceType);
        })->where('p.business_id', $id)->where('p.is_active', true);
        $q = trim($filters['q'] ?? '');
        if ($q !== '') $query->where(function ($query) use ($q) {
            foreach (['p.name', 'p.sku', 'p.barcode', 'p.category'] as $index => $column) {
                $index === 0 ? $query->where($column, 'like', '%'.$q.'%') : $query->orWhere($column, 'like', '%'.$q.'%');
            }
        });
        $category = $filters['category'] ?? '';
        if ($category !== '') $query->where('p.category', $category);
        $lowStock = 'p.stock > 0 AND p.low_stock_threshold > 0 AND p.stock <= p.low_stock_threshold';
        switch ($filters['stock_status'] ?? 'all') {
            case 'low_stock': $query->whereRaw($lowStock); break;
            case 'out_of_stock': $query->where('p.stock', '<=', 0); break;
            case 'in_stock': $query->where('p.stock', '>', 0)->where(fn ($q) => $q->where('p.low_stock_threshold', '<=', 0)->orWhereColumn('p.stock', '>', 'p.low_stock_threshold')); break;
        }
        switch ($filters['publication_status'] ?? 'all') {
            case 'published': $query->where('ep.published', true); break;
            case 'unpublished': $query->where(fn ($q) => $q->whereNull('ep.published')->orWhere('ep.published', false)); break;
        }
        $onlinePrice = "CASE WHEN ep.unit = 'purchase' THEN CASE WHEN p.purchase_unit IS NOT NULL AND p.purchase_unit <> '' AND p.purchase_conversion_factor > 0 THEN ROUND(pp.price * p.purchase_conversion_factor) ELSE NULL END ELSE pp.price END";
        if (($filters['price_status'] ?? '') === 'priced') $query->whereRaw("($onlinePrice) > 0");
        if (($filters['price_status'] ?? '') === 'missing') $query->whereRaw("(($onlinePrice) IS NULL OR ($onlinePrice) <= 0)");
        switch ($filters['sort'] ?? 'name_asc') {
            case 'name_desc': $query->orderByDesc('p.name'); break;
            case 'low_stock_first': $query->orderByRaw("CASE WHEN p.stock <= 0 THEN 0 WHEN $lowStock THEN 1 ELSE 2 END")->orderBy('p.stock')->orderBy('p.name'); break;
            case 'stock_asc': $query->orderBy('p.stock')->orderBy('p.name'); break;
            case 'stock_desc': $query->orderByDesc('p.stock')->orderBy('p.name'); break;
            case 'price_asc': case 'price_desc':
                $query->orderByRaw("CASE WHEN ($onlinePrice) IS NULL THEN 1 ELSE 0 END")->orderByRaw("($onlinePrice) ".($filters['sort'] === 'price_desc' ? 'DESC' : 'ASC'))->orderBy('p.name'); break;
            case 'newest': $query->orderByDesc('p.id'); break;
            case 'oldest': $query->orderBy('p.id'); break;
            default: $query->orderBy('p.name'); break;
        }
        return $query;
    }

    public function publishFiltered(Request $request): array
    {
        $data = $request->validate(['published' => ['required', 'boolean'],
            'product_ids' => ['sometimes', 'array', 'min:1', 'max:1000'],
            'product_ids.*' => ['required', 'integer', 'min:1', 'distinct']]);
        $published = $data['published'];
        $selected = $data['product_ids'] ?? null;
        $businessId = $this->tenant->requireId();
        return DB::transaction(function () use ($request, $published, $businessId, $selected) {
            $store = DB::table('storefront_settings')->where('business_id', $businessId)->lockForUpdate()->first();
            $rows = $this->publicationQuery($request)->when($selected !== null, fn ($q) => $q->whereIn('p.id', $selected))->reorder()->orderBy('p.id')->lockForUpdate()
                ->get(['p.id', 'p.name', 'p.purchase_unit', 'p.purchase_conversion_factor', 'ep.unit', 'ep.published', 'pp.price as base_price']);
            if ($selected !== null && $rows->count() !== count($selected)) {
                throw \Illuminate\Validation\ValidationException::withMessages(['product_ids' => 'Some selected products are no longer available. Review your selection and try again.']);
            }
            $updates = [];
            foreach ($rows as $row) {
                if ((bool) $row->published === (bool) $published) continue;
                $unit = $row->unit ?: 'base';
                if ($published) {
                    abort_unless($store, 422, 'Configure your storefront first.');
                    abort_if($row->base_price === null || $row->base_price <= 0, 422, $row->name.': Set a valid online price first.');
                    abort_if($unit === 'purchase' && (! $row->purchase_unit || $row->purchase_conversion_factor <= 0), 422, $row->name.': This product has no supported purchase unit.');
                    $price = $row->base_price * ($unit === 'purchase' ? $row->purchase_conversion_factor : 1);
                    abort_if($price < 1 || $price > 1000000000, 422, $row->name.': Online unit prices must be between 1 and 1,000,000,000 currency units.');
                }
                $updates[] = ['business_id' => $businessId, 'product_id' => $row->id, 'unit' => $unit, 'published' => $published,
                    'created_at' => now(), 'updated_at' => now()];
            }
            foreach (array_chunk($updates, 500) as $batch) {
                DB::table('ecommerce_product_settings')->upsert($batch, ['business_id', 'product_id'], ['published', 'updated_at']);
            }
            return ['items' => $rows->map(fn ($row) => ['id' => $row->id, 'published' => (bool) $published, 'unit' => $row->unit ?: 'base'])->values(),
                'matched' => $rows->count(), 'updated' => count($updates)];
        });
    }

    public function publish(Request $request, int $id): array
    {
        $businessId = $this->tenant->requireId();
        $data = $request->validate(['published' => ['required', 'boolean'], 'unit' => ['required', 'in:base,purchase'], 'description' => ['nullable', 'string', 'max:4000']]);
        DB::transaction(function () use ($businessId, $id, $data) {
            $p = DB::table('products')->where('business_id', $businessId)->where('id', $id)->where('is_active', true)->lockForUpdate()->first();
            abort_unless($p, 404, 'Product unavailable.');
            if ($data['published']) {
                $store = DB::table('storefront_settings')->where('business_id', $businessId)->first();
                abort_unless($store, 422, 'Configure your storefront first.');
                abort_unless(DB::table('product_prices')->where('business_id', $businessId)->where('product_id', $id)->where('name', $store->price_type)->where('price', '>', 0)->exists(), 422, 'Set a valid price for the storefront price type first.');
                abort_if($data['unit'] === 'purchase' && (! $p->purchase_unit || $p->purchase_conversion_factor <= 0), 422, 'This product has no supported purchase unit.');
                $basePrice = DB::table('product_prices')->where('business_id', $businessId)->where('product_id', $id)->where('name', $store->price_type)->value('price');
                $selectedPrice = $basePrice * ($data['unit'] === 'purchase' ? $p->purchase_conversion_factor : 1);
                abort_if($selectedPrice < 1 || $selectedPrice > 1000000000, 422, 'Online unit prices must be between 1 and 1,000,000,000 currency units.');
            }
            DB::table('ecommerce_product_settings')->updateOrInsert(['business_id' => $businessId, 'product_id' => $id], $data + ['created_at' => now(), 'updated_at' => now()]);
        });
        return ['ok' => true];
    }

    public function orders(Request $request): array
    {
        $rules = ['sort' => ['sometimes', 'in:newest,oldest'], 'date_from' => ['nullable', 'date_format:Y-m-d'], 'date_to' => ['nullable', 'date_format:Y-m-d']];
        if ($request->filled('date_from')) $rules['date_to'][] = 'after_or_equal:date_from';
        $dates = $request->validate($rules);
        $businessId = $this->tenant->requireId();
        $query = DB::table('ecommerce_orders')->where('business_id', $businessId);
        if (! empty($dates['date_from']) || ! empty($dates['date_to'])) {
            $timezone = DB::table('businesses')->where('id', $businessId)->value('timezone') ?: 'Asia/Rangoon';
            if (! empty($dates['date_from'])) $query->where('created_at', '>=', \App\Support\AppTime::day($dates['date_from'], $timezone));
            if (! empty($dates['date_to'])) $query->where('created_at', '<', \App\Support\AppTime::storage(CarbonImmutable::createFromFormat('!Y-m-d', $dates['date_to'], $timezone)->addDay()));
        }
        if ($s = $request->query('status')) $query->where('status', $s);
        if ($p = $request->query('payment_status')) $query->where('payment_status', $p);
        if ($request->boolean('requests')) $query->whereExists(fn ($q) => $q->selectRaw('1')->from('shop_order_requests as cr')->whereColumn('cr.order_id', 'ecommerce_orders.id')->where('cr.business_id', $businessId)->where(fn ($q) => $q->where('cr.status', 'pending')->orWhere(fn ($q) => $q->where('cr.type', 'return')->where('cr.status', 'approved'))));
        if ($q = $request->query('q')) $query->where(fn ($b) => $b->where('reference', 'like', '%'.$q.'%')->orWhere('customer_name', 'like', '%'.$q.'%'));
        $total = (clone $query)->count();
        $limit = max(1, min(100, (int) $request->query('limit', 30)));
        $rows = $query->orderBy('id', ($dates['sort'] ?? 'newest') === 'oldest' ? 'asc' : 'desc')->offset(max(0, (int) $request->query('offset', 0)))->limit($limit + 1)
            ->get(['id', 'reference', 'customer_name', 'phone', 'platform_customer_id', 'status', 'payment_status', 'fulfillment', 'payment_method', 'currency', 'total', 'created_at']);
        foreach ($rows as $row) {
            $row->request_count = DB::table('shop_order_requests')->where('business_id', $businessId)->where('order_id', $row->id)
                ->where(fn ($q) => $q->where('status', 'pending')->orWhere(fn ($q) => $q->where('type', 'return')->where('status', 'approved')))->count();
            $row->payment_method = json_decode($row->payment_method, true);
            $row->customer_key = \App\Services\ShopCustomerAccess::key($row->phone, $row->platform_customer_id === null ? null : (int) $row->platform_customer_id);
            unset($row->phone, $row->platform_customer_id);
        }
        return ['items' => app(\App\Services\ShopOrderCards::class)->enrich($rows->take($limit)->values(), $businessId), 'has_more' => $rows->count() > $limit, 'total' => $total];
    }

    public function order(int $id): array
    {
        $order = DB::table('ecommerce_orders')->where('business_id', $this->tenant->requireId())->where('id', $id)->first();
        abort_unless($order, 404, 'Order unavailable.');
        $detail = $this->commerce->orderView($order, true);
        $card = app(\App\Services\ShopOrderCards::class)->enrich(collect([(object) ['id' => $id]]), $this->tenant->requireId(), PHP_INT_MAX)->first();
        $photos = collect($card->item_previews)->keyBy('product_id');
        $detail['items'] = collect($detail['items'])->map(fn ($item) => (array) $item + ['photo_url' => $photos->get($item->product_id)['photo_url'] ?? null])->all();
        return $detail;
    }

    public function orderItemPhoto(int $id, int $productId)
    {
        $businessId = $this->tenant->requireId();
        $item = DB::table('ecommerce_order_items as i')->join('ecommerce_orders as o', 'o.id', '=', 'i.order_id')
            ->join('products as p', fn ($join) => $join->on('p.id', '=', 'i.product_id')->on('p.business_id', '=', 'i.business_id'))
            ->where('o.business_id', $businessId)->where('i.business_id', $businessId)->where('i.order_id', $id)->where('i.product_id', $productId)->first(['p.photo_path']);
        abort_unless($item && $item->photo_path && Storage::disk('local')->exists($item->photo_path), 404, 'Product photo not found.');
        return response()->file(Storage::disk('local')->path($item->photo_path), ['Cache-Control' => 'private, no-cache', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function transition(Request $request, int $id): array
    {
        $data = $request->validate(['status' => ['required', 'in:accepted,packing,ready_for_pickup,dispatched,return_pending,completed,cancelled'],
            'reason' => ['nullable', 'string', 'max:2000'], 'return_confirmed' => ['sometimes', 'boolean']]);
        return $this->commerce->transition($this->tenant->requireId(), $id, $data['status'], $request->user('web')->id, $data['reason'] ?? null, $data['return_confirmed'] ?? false);
    }

    public function payment(Request $request, int $id, string $action): array
    {
        $rules = $action === 'reject' ? ['reason' => ['required', 'string', 'max:2000']]
            : ['amount' => ['required', 'integer', 'min:0'], 'reference' => ['nullable', 'string', 'max:255'],
                'verified' => ['required', 'accepted'], 'review_note' => ['nullable', 'string', 'max:2000']];
        return $this->commerce->payment($this->tenant->requireId(), $id, $action, $request->validate($rules), $request->user('web')->id);
    }

    public function reopen(Request $request, int $id): array
    {
        return $this->commerce->reopen($this->tenant->requireId(), $id, $request->user('web')->id);
    }

    public function proof(int $id, int $proofId)
    {
        $p = DB::table('ecommerce_payments')->where('business_id', $this->tenant->requireId())->where('order_id', $id)->where('id', $proofId)->where('type', 'proof')->first();
        abort_unless($p && $p->proof_path && Storage::disk('local')->exists($p->proof_path), 404);
        return response()->file(Storage::disk('local')->path($p->proof_path), ['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function report(Request $request): array
    {
        if ($request->boolean('analytics')) return app(\App\Services\ShopAnalytics::class)->report($request, $this->tenant->requireId());
        $query = DB::table('ecommerce_orders')->where('business_id', $this->tenant->requireId());
        return ['states' => (clone $query)->select('status', 'currency')->selectRaw('COUNT(*) as orders, SUM(total) as amount')->groupBy('status', 'currency')->get(),
            'payments' => (clone $query)->select('payment_status', 'currency')->selectRaw('COUNT(*) as orders, SUM(total) as amount')->groupBy('payment_status', 'currency')->get(),
            'prepaid' => (clone $query)->where('payment_status', 'paid')->whereNotIn('status', ['completed', 'cancelled', 'expired'])
                ->select('currency')->selectRaw('COUNT(*) as orders, SUM(total) as amount')->groupBy('currency')->get(),
            'origins' => (clone $query)->select('origin')->selectRaw('COUNT(*) as orders')->groupBy('origin')->get()];
    }
}
