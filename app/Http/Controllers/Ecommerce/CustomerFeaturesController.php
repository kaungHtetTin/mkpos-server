<?php

namespace App\Http\Controllers\Ecommerce;

use App\Http\Controllers\Controller;
use App\Services\EcommerceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerFeaturesController extends Controller
{
    public function __construct(private EcommerceService $commerce) {}
    private function customer(Request $request): int { return (int) $request->attributes->get('platform_customer')->id; }

    public function favoriteKeys(Request $request): array
    {
        return ['items' => DB::table('shop_customer_favorites as f')->join('storefront_settings as s', 's.business_id', '=', 'f.business_id')
            ->where('f.customer_id', $this->customer($request))->get(['s.slug', 'f.product_id'])];
    }

    public function notifications(Request $request): array
    {
        $limit = max(1, min(48, (int) $request->query('limit', 24)));
        $query = DB::table('shop_customer_notifications')->where('customer_id', $this->customer($request));
        $unread = (clone $query)->whereNull('read_at')->count();
        if ($request->boolean('unread')) $query->whereNull('read_at');
        $rows = $query->orderByDesc('id')->offset(max(0, (int) $request->query('offset', 0)))->limit($limit + 1)->get();
        return ['unread_count' => $unread, 'items' => $rows->take($limit)->map(fn ($row) => ['id' => $row->id, 'event' => $row->event,
            'payload' => json_decode($row->payload, true), 'read_at' => \App\Support\AppTime::utc($row->read_at), 'created_at' => \App\Support\AppTime::utc($row->created_at)])->values(), 'has_more' => $rows->count() > $limit];
    }

    public function readNotifications(Request $request): array
    {
        $data = $request->validate(['ids' => ['sometimes', 'array', 'max:100'], 'ids.*' => ['integer', 'min:1']]);
        $query = DB::table('shop_customer_notifications')->where('customer_id', $this->customer($request))->whereNull('read_at');
        if (array_key_exists('ids', $data)) $query->whereIn('id', $data['ids']);
        $through = (clone $query)->max('id') ?: 0;
        $query->where('id', '<=', $through)->update(['read_at' => now(), 'updated_at' => now()]);
        return ['ok' => true, 'read_through' => $through];
    }

    public function stockAlert(Request $request, string $slug, int $id): array
    {
        $store = $this->commerce->store($slug);
        $p = $this->commerce->catalogQuery($store)->where('p.id', $id)->first();
        abort_unless($p, 404);
        $query = DB::table('shop_stock_alerts')->where('customer_id', $this->customer($request))->where('business_id', $store->business_id)->where('product_id', $id);
        if ($request->isMethod('get')) return ['active' => (bool) $query->value('active')];
        if ($request->isMethod('delete')) { $query->update(['active' => false, 'updated_at' => now()]); return ['active' => false]; }
        abort_if($this->commerce->productView($store, $p)['available_quantity'] > 0, 409, 'This product is already available.');
        abort_if(DB::table('shop_stock_alerts')->where('customer_id', $this->customer($request))->where('active', true)->count() >= 100 && !(clone $query)->where('active', true)->exists(), 422, 'You can follow up to 100 stock alerts.');
        DB::table('shop_stock_alerts')->upsert([['customer_id' => $this->customer($request), 'business_id' => $store->business_id,
            'product_id' => $id, 'active' => true, 'notified_at' => null, 'created_at' => now(), 'updated_at' => now()]], ['customer_id', 'product_id'], ['active', 'notified_at', 'updated_at']);
        return ['active' => true, 'alert_id' => $query->value('id')];
    }

    public function stockAlerts(Request $request): array
    {
        $limit = max(1, min(48, (int) $request->query('limit', 24)));
        $rows = DB::table('shop_stock_alerts as a')->join('storefront_settings as s', 's.business_id', '=', 'a.business_id')
            ->where('a.customer_id', $this->customer($request))->where('a.active', true)->select('a.id', 'a.product_id', 's.slug')
            ->orderByDesc('a.id')->offset(max(0, (int) $request->query('offset', 0)))->limit($limit + 1)->get();
        $items = $rows->take($limit)->map(function ($row) {
            $result = ['alert_id' => $row->id, 'slug' => $row->slug, 'product_id' => $row->product_id, 'unavailable' => true];
            try {
                $store = $this->commerce->store($row->slug);
                $p = $this->commerce->catalogQuery($store)->where('p.id', $row->product_id)->first();
                if ($p) return $this->commerce->productView($store, $p) + ['alert_id' => $row->id];
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { if ($e->getStatusCode() !== 404) throw $e; }
            return $result;
        });
        return ['items' => $items->values(), 'has_more' => $rows->count() > $limit];
    }

    public function removeStockAlert(Request $request, int $id): array
    {
        DB::table('shop_stock_alerts')->where('customer_id', $this->customer($request))->where('id', $id)->update(['active' => false, 'updated_at' => now()]);
        return ['ok' => true];
    }

    public function favorites(Request $request): array
    {
        $kind = $request->query('kind', 'products');
        $limit = max(1, min(48, (int) $request->query('limit', 24)));
        $offset = max(0, (int) $request->query('offset', 0));
        $query = DB::table('shop_customer_favorites as f')->join('storefront_settings as s', 's.business_id', '=', 'f.business_id')
            ->where('f.customer_id', $this->customer($request));
        $kind === 'shops' ? $query->where('f.product_id', 0) : $query->where('f.product_id', '>', 0);
        // Keep unavailable saved rows removable; never expose hidden catalog details.
        $rows = $query->select('f.id', 'f.product_id', 's.slug')->orderByDesc('f.id')->offset($offset)->limit($limit + 1)->get();
        $items = $rows->take($limit)->map(function ($row) {
            $result = ['favorite_id' => $row->id, 'product_id' => $row->product_id, 'slug' => $row->slug, 'unavailable' => true];
            try {
                $store = $this->commerce->store($row->slug);
                if (!$row->product_id) return $this->commerce->storeView($store) + ['favorite_id' => $row->id];
                $p = $this->commerce->catalogQuery($store)->where('p.id', $row->product_id)->first();
                if ($p) return $this->commerce->productView($store, $p) + ['favorite_id' => $row->id];
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { if ($e->getStatusCode() !== 404) throw $e; }
            return $result;
        });
        return ['items' => $items->values(), 'has_more' => $rows->count() > $limit];
    }

    public function favorite(Request $request, string $slug): array
    {
        $data = $request->validate(['product_id' => ['required', 'integer', 'min:0']]);
        $store = DB::table('storefront_settings')->where('slug', $slug)->first();
        abort_unless($store, 404);
        $query = DB::table('shop_customer_favorites')->where('customer_id', $this->customer($request))->where('business_id', $store->business_id)->where('product_id', $data['product_id']);
        if ($request->isMethod('get')) return ['saved' => $query->exists()];
        if ($request->isMethod('delete')) { $query->delete(); return ['saved' => false]; }
        $store = $this->commerce->store($slug);
        if ($data['product_id']) abort_unless($this->commerce->catalogQuery($store)->where('p.id', $data['product_id'])->exists(), 404);
        abort_if(DB::table('shop_customer_favorites')->where('customer_id', $this->customer($request))->count() >= 1000 && !$query->exists(), 422, 'You have reached the saved item limit.');
        DB::table('shop_customer_favorites')->insertOrIgnore(['customer_id' => $this->customer($request), 'business_id' => $store->business_id,
            'product_id' => $data['product_id'], 'created_at' => now(), 'updated_at' => now()]);
        return ['saved' => true];
    }

    public function removeFavorite(Request $request, int $id): array
    {
        DB::table('shop_customer_favorites')->where('customer_id', $this->customer($request))->where('id', $id)->delete();
        return ['ok' => true];
    }

    public function addresses(Request $request): array
    {
        return ['items' => DB::table('shop_customer_addresses')->where('customer_id', $this->customer($request))->orderByDesc('is_default')->orderByDesc('id')->get()];
    }

    public function saveAddress(Request $request, ?int $id = null): array
    {
        $data = $request->validate(['label' => ['required', 'string', 'max:80'], 'recipient' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:80'], 'address' => ['required', 'string', 'max:2000'], 'is_default' => ['required', 'boolean']]);
        DB::transaction(function () use ($request, $id, $data) {
            $customer = $this->customer($request);
            DB::table('platform_customers')->where('id', $customer)->lockForUpdate()->first();
            $query = DB::table('shop_customer_addresses')->where('customer_id', $customer);
            if ($id) abort_unless((clone $query)->where('id', $id)->exists(), 404);
            else abort_if($query->count() >= 20, 422, 'You can save up to 20 addresses.');
            if (!$query->exists()) $data['is_default'] = true;
            if ($data['is_default']) $query->update(['is_default' => false, 'updated_at' => now()]);
            if ($id) (clone $query)->where('id', $id)->update($data + ['updated_at' => now()]);
            else DB::table('shop_customer_addresses')->insert($data + ['customer_id' => $customer, 'created_at' => now(), 'updated_at' => now()]);
            $default = (clone $query)->where('is_default', true)->first();
            if (!$default) {
                $default = (clone $query)->orderByDesc('id')->first();
                (clone $query)->where('id', $default->id)->update(['is_default' => true, 'updated_at' => now()]);
            }
            DB::table('platform_customers')->where('id', $customer)->update(['address' => $default->address, 'updated_at' => now()]);
        });
        return $this->addresses($request);
    }

    public function deleteAddress(Request $request, int $id): array
    {
        DB::transaction(function () use ($request, $id) {
            $customer = $this->customer($request);
            DB::table('platform_customers')->where('id', $customer)->lockForUpdate()->first();
            $query = DB::table('shop_customer_addresses')->where('customer_id', $customer);
            $row = (clone $query)->where('id', $id)->first();
            abort_unless($row, 404);
            (clone $query)->where('id', $id)->delete();
            if ($row->is_default) {
                $next = (clone $query)->orderByDesc('id')->first();
                if ($next) (clone $query)->where('id', $next->id)->update(['is_default' => true, 'updated_at' => now()]);
                DB::table('platform_customers')->where('id', $customer)->update(['address' => $next->address ?? null, 'updated_at' => now()]);
            }
        });
        return $this->addresses($request);
    }
}
