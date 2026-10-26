<?php

namespace App\Http\Controllers\Ecommerce;

use App\Http\Controllers\Controller;
use App\Services\EcommerceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ShopController extends Controller
{
    public function __construct(private EcommerceService $commerce) {}

    public function show(string $slug): array
    {
        $store = $this->commerce->store($slug);
        $view = $this->commerce->storeView($store);
        $view['categories'] = $this->commerce->catalogQuery($store)->reorder()->select('p.category')->distinct()->orderBy('p.category')->pluck('p.category');
        return $view;
    }

    public function products(Request $request, string $slug): array
    {
        $store = $this->commerce->store($slug);
        $query = $this->commerce->catalogQuery($store);
        app(\App\Services\ShopProductSearch::class)->apply($query, $request);
        if ($category = $request->query('category')) $query->where('p.category', $category);
        $limit = max(1, min(48, (int) $request->query('limit', 24)));
        $offset = max(0, (int) $request->query('offset', 0));
        app(\App\Services\ShopCatalogFilters::class)->apply($query, $request);
        $rows = $query->offset($offset)->limit($limit + 1)->get();
        return ['items' => $rows->take($limit)->map(fn ($p) => $this->commerce->productView($store, $p))->values(),
            'has_more' => $rows->count() > $limit, 'offset' => $offset, 'limit' => $limit];
    }

    public function product(string $slug, int $id): array
    {
        $store = $this->commerce->store($slug);
        $p = $this->commerce->catalogQuery($store)->where('p.id', $id)->first();
        abort_unless($p, 404, 'Product unavailable.');
        return $this->commerce->productView($store, $p);
    }

    public function related(string $slug, int $id): array
    {
        $store = $this->commerce->store($slug);
        $p = $this->commerce->catalogQuery($store)->where('p.id', $id)->first();
        abort_unless($p, 404);
        $query = $this->commerce->catalogQuery($store)->where('p.id', '<>', $id);
        if ($p->category) $query->where('p.category', $p->category);
        app(\App\Services\ShopCatalogFilters::class)->apply($query, new Request(['in_stock' => '1']));
        return ['items' => $query->limit(6)->get()->map(fn ($row) => $this->commerce->productView($store, $row))->values()];
    }

    public function photo(string $slug, int $id)
    {
        $store = $this->commerce->store($slug);
        $p = $this->commerce->catalogQuery($store)->where('p.id', $id)->first();
        abort_unless($p && $p->photo_path && Storage::disk('local')->exists($p->photo_path), 404);
        return response()->file(Storage::disk('local')->path($p->photo_path), ['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function logo(string $slug)
    {
        $store = $this->commerce->store($slug);
        $path = DB::table('settings')->where('business_id', $store->business_id)->where('key', 'receipt_logo_path')->value('value');
        abort_unless($path && Storage::disk('local')->exists($path), 404);
        return response()->file(Storage::disk('local')->path($path), ['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function cover(string $slug)
    {
        $store = $this->commerce->store($slug);
        $path = DB::table('settings')->where('business_id', $store->business_id)->where('key', ShopCoverController::SETTING_KEY)->value('value');
        abort_unless($path && Storage::disk('local')->exists($path), 404);
        return response()->file(Storage::disk('local')->path($path), ['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function checkoutData(Request $request, bool $submit): array
    {
        $rules = [
            'coupon_code' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['required', 'integer', 'min:1', 'distinct'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001', 'max:100000', 'regex:/^\d+(\.\d{1,3})?$/'],
            'fulfillment' => ['required', 'in:pickup,delivery'],
            'delivery_zone_id' => ['required_if:fulfillment,delivery', 'nullable', 'string', 'max:50'],
            'payment_method_id' => ['required', 'string', 'max:50'], 'origin' => ['sometimes', 'in:storefront,marketplace'],
        ];
        if ($submit) $rules += [
            'customer_name' => ['required', 'string', 'max:255'], 'phone' => ['required', 'string', 'max:80'],
            'email' => ['nullable', 'email', 'max:255'], 'address' => ['required_if:fulfillment,delivery', 'nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'], 'idempotency_key' => ['required', 'uuid'],
            'quote_hash' => ['required', 'string', 'size:64'],
        ];
        return $request->validate($rules);
    }

    public function quote(Request $request, string $slug): array
    {
        return $this->commerce->quote($this->commerce->store($slug), $this->checkoutData($request, false));
    }

    public function checkout(Request $request, string $slug): array
    {
        // Resolve settings even if the shop has since closed so an authorized
        // retry can still return its already committed order and credential.
        $store = DB::table('storefront_settings')->where('slug', $slug)->first();
        abort_unless($store, 404, 'Shop unavailable.');
        return $this->commerce->checkout($store, $this->checkoutData($request, true), $request->attributes->get('platform_customer')?->id);
    }

    public function tracking(Request $request): array
    {
        $data = $request->validate(['reference' => ['required', 'string', 'max:40'], 'credential' => ['required', 'string', 'size:64']]);
        return $this->commerce->orderView($this->commerce->track($data['reference'], $data['credential']));
    }

    public function proof(Request $request): array
    {
        $data = $request->validate(['reference' => ['required', 'string', 'max:40'], 'credential' => ['required', 'string', 'size:64'],
            'proof' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:2048']]);
        $order = $this->commerce->track($data['reference'], $data['credential']);
        $path = $data['proof']->store('ecommerce-proofs/'.$order->business_id, 'local');
        try {
            return DB::transaction(function () use ($order, $path) {
                $order = DB::table('ecommerce_orders')->where('id', $order->id)->where('business_id', $order->business_id)->lockForUpdate()->first();
                $method = json_decode($order->payment_method, true);
                abort_unless($method['type'] === 'transfer' && ($method['provider'] ?? 'manual') === 'manual' && ! in_array($order->status, ['completed', 'cancelled', 'expired'])
                    && $order->payment_status === 'unpaid', 409, 'This order cannot receive a payment proof.');
                DB::table('ecommerce_payments')->insert(['business_id' => $order->business_id, 'order_id' => $order->id, 'type' => 'proof',
                    'status' => 'pending', 'proof_path' => $path, 'created_at' => now(), 'updated_at' => now()]);
                DB::table('ecommerce_orders')->where('id', $order->id)->where('business_id', $order->business_id)->update(['payment_status' => 'proof_submitted', 'updated_at' => now()]);
                $this->commerce->event($order->business_id, $order->id, 'proof_submitted');
                return ['ok' => true];
            });
        } catch (\Throwable $e) { Storage::disk('local')->delete($path); throw $e; }
    }

    public function discovery(Request $request): array
    {
        $limit = max(1, min(48, (int) $request->query('limit', 24)));
        $offset = max(0, (int) $request->query('offset', 0));
        // Eligibility is evaluated in SQL before pagination; excluded shops must
        // not create empty pages which prematurely end endless scrolling.
        $query = DB::table('storefront_settings as sf')->join('businesses as b', 'b.id', '=', 'sf.business_id')
            ->where('b.status', 'active')->where('sf.storefront_enabled', true)->where('sf.marketplace_enabled', true)->where('sf.marketplace_suspended', false)
            ->whereExists(function ($q) {
                $q->selectRaw('1')->from('business_subscriptions as bs')->whereColumn('bs.business_id', 'b.id')
                    ->whereIn('bs.status', ['active', 'cancelled'])->where('bs.starts_at', '<=', now())
                    ->where(function ($q) { $q->where('bs.ends_at', '>', now())->orWhere(function ($q) { $q->whereNull('bs.ends_at')->where('bs.status', 'active'); }); });
            });
        if ($search = trim((string) $request->query('q'))) $query->where('sf.display_name', 'like', '%'.substr($search, 0, 120).'%');
        $query->select('sf.*')->orderBy('sf.id');
        app(\App\Services\ShopFeaturedRanking::class)->apply($query, $request, false);
        $rows = $query->offset($offset)->limit($limit + 1)->get();
        return ['items' => $rows->take($limit)->map(fn ($s) => $this->commerce->storeView($s))->values(), 'has_more' => $rows->count() > $limit];
    }

    public function marketplaceProducts(Request $request): array
    {
        $filters = $request->validate(['marketplace_category_id' => ['nullable', 'integer', 'min:1']]);
        $limit = max(1, min(48, (int) $request->query('limit', 24)));
        $offset = max(0, (int) $request->query('offset', 0));
        $query = DB::table('products as p')->join('ecommerce_product_settings as ep', function ($j) {
            $j->on('ep.product_id', '=', 'p.id')->on('ep.business_id', '=', 'p.business_id');
        })->join('storefront_settings as sf', 'sf.business_id', '=', 'p.business_id')
            ->join('businesses as b', 'b.id', '=', 'p.business_id')
            ->join('product_prices as pp', function ($j) {
                $j->on('pp.product_id', '=', 'p.id')->on('pp.business_id', '=', 'p.business_id')->on('pp.name', '=', 'sf.price_type');
            })->where('p.is_active', true)->where('ep.published', true)->where('pp.price', '>', 0)
            ->whereRaw("(CASE WHEN ep.unit = 'purchase' THEN pp.price * p.purchase_conversion_factor ELSE pp.price END) BETWEEN 1 AND 1000000000")
            ->where('b.status', 'active')->where('sf.storefront_enabled', true)->where('sf.marketplace_enabled', true)->where('sf.marketplace_suspended', false)
            ->where(function ($q) { $q->where('ep.unit', 'base')->orWhere(function ($q) {
                $q->where('ep.unit', 'purchase')->whereNotNull('p.purchase_unit')->where('p.purchase_unit', '<>', '')->where('p.purchase_conversion_factor', '>', 0);
            }); })
            ->whereExists(function ($q) {
                $q->selectRaw('1')->from('business_subscriptions as bs')->whereColumn('bs.business_id', 'b.id')
                    ->whereIn('bs.status', ['active', 'cancelled'])->where('bs.starts_at', '<=', now())
                    ->where(function ($q) { $q->where('bs.ends_at', '>', now())->orWhere(function ($q) { $q->whereNull('bs.ends_at')->where('bs.status', 'active'); }); });
            });
        app(\App\Services\ShopProductSearch::class)->apply($query, $request);
        if (!empty($filters['marketplace_category_id'])) {
            $taxonomy = app(\App\Services\MarketplaceTaxonomy::class); $categories = $taxonomy->categories();
            $selected = $categories->get($filters['marketplace_category_id']);
            $ids = $selected && $selected['effective_active'] ? array_filter($taxonomy->descendants((int) $selected['id'], $categories), fn ($id) => $categories->get($id)['effective_active']) : [];
            $query->whereExists(fn ($link) => $link->selectRaw('1')->from('marketplace_product_categories as mc')
                ->whereColumn('mc.product_id', 'p.id')->whereColumn('mc.business_id', 'p.business_id')->whereIn('mc.category_id', $ids));
        }
        if ($category = $request->query('category')) $query->where('p.category', $category);
        $query->select('p.*', 'pp.price as selected_price', 'ep.unit as selected_unit', 'ep.description as public_description',
            'sf.slug as store_slug', 'sf.display_name as store_name')
            ->selectSub(DB::table('shop_reviews as r')->selectRaw('COUNT(*)')->whereColumn('r.business_id', 'p.business_id')->whereColumn('r.target_id', 'p.id'), 'review_count')
            ->selectSub(DB::table('shop_reviews as r')->selectRaw('AVG(r.rating)')->whereColumn('r.business_id', 'p.business_id')->whereColumn('r.target_id', 'p.id'), 'review_average');
        app(\App\Services\ShopCatalogFilters::class)->apply($query, $request);
        if (! $request->filled('sort') || $request->query('sort') === 'default') {
            app(\App\Services\ShopFeaturedRanking::class)->apply($query, $request, true);
        }
        $rows = $query->offset($offset)->limit($limit + 1)->get();
        return ['items' => $rows->take($limit)->map(fn ($p) => $this->commerce->productView((object) ['slug' => $p->store_slug, 'display_name' => $p->store_name], $p))->values(),
            'has_more' => $rows->count() > $limit];
    }

    public function marketplaceCategories(Request $request): array
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:120'], 'level' => ['nullable', 'in:all,root'],
            'offset' => ['nullable', 'integer', 'min:0'], 'limit' => ['nullable', 'integer', 'between:1,48']]);
        $taxonomy = app(\App\Services\MarketplaceTaxonomy::class); $categories = $taxonomy->categories();
        $ids = $categories->filter(fn ($row) => $row['effective_active'])->keys();
        $query = DB::table('marketplace_categories')->whereIn('id', $ids);
        if (($data['level'] ?? 'all') === 'root') $query->whereNull('parent_id');
        if ($q = mb_strtolower(trim($data['q'] ?? ''))) {
            $matches = $categories->filter(fn ($row) => str_contains(mb_strtolower($row['path'].' '.$row['path_my'].' '.$row['slug']), $q))->keys();
            $query->whereIn('id', $matches);
        }
        $limit = (int) ($data['limit'] ?? 24); $offset = (int) ($data['offset'] ?? 0);
        $rows = $query->orderBy('sort_order')->orderBy('name_en')->orderBy('id')->offset($offset)->limit($limit + 1)->get(['id']);
        return ['items' => $rows->take($limit)->map(fn ($row) => array_intersect_key($categories->get($row->id), array_flip(['id', 'parent_id', 'name_en', 'name_my', 'slug', 'path', 'path_my'])))->values(), 'has_more' => $rows->count() > $limit];
    }
}
