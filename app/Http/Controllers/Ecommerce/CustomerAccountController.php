<?php

namespace App\Http\Controllers\Ecommerce;

use App\Http\Controllers\Controller;
use App\Services\EcommerceService;
use App\Services\PlatformCustomerSessions;
use App\Services\PlatformCustomerAvatar;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class CustomerAccountController extends Controller
{
    public function __construct(private PlatformCustomerSessions $sessions, private EcommerceService $commerce) {}

    private function normalizeEmail(Request $request): void
    {
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);
    }

    public function register(Request $request): array
    {
        $this->normalizeEmail($request);
        $data = $request->validate(['name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:platform_customers,email'],
            'password' => ['required', 'string', 'min:8', 'max:128', 'confirmed'],
            'phone' => ['nullable', 'string', 'max:80']]);
        return DB::transaction(function () use ($data) {
            $id = DB::table('platform_customers')->insertGetId(['name' => $data['name'], 'email' => $data['email'],
                'password' => Hash::make($data['password']), 'phone' => $data['phone'] ?? null, 'created_at' => now(), 'updated_at' => now()]);
            return $this->sessions->issue(DB::table('platform_customers')->where('id', $id)->first());
        });
    }

    public function login(Request $request): array
    {
        $this->normalizeEmail($request);
        $data = $request->validate(['email' => ['required', 'email', 'max:255'], 'password' => ['required', 'string', 'max:128']]);
        $customer = DB::table('platform_customers')->where('email', $data['email'])->where('is_active', true)->first();
        if (! $customer || ! Hash::check($data['password'], $customer->password)) {
            throw ValidationException::withMessages(['email' => 'The customer email or password is incorrect.']);
        }
        return $this->sessions->issue($customer);
    }

    public function me(Request $request): array
    {
        return ['customer' => $this->sessions->view($request->attributes->get('platform_customer'))];
    }

    public function profile(Request $request): array
    {
        $customer = $request->attributes->get('platform_customer');
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'phone' => ['nullable', 'string', 'max:80'],
            'address' => ['nullable', 'string', 'max:2000']]);
        DB::transaction(function () use ($customer, $data) {
            DB::table('platform_customers')->where('id', $customer->id)->lockForUpdate()->first();
            DB::table('platform_customers')->where('id', $customer->id)->update($data + ['updated_at' => now()]);
            DB::table('shop_customer_addresses')->where('customer_id', $customer->id)->where('is_default', true)->update(['address' => $data['address'] ?? '', 'updated_at' => now()]);
        });
        return ['customer' => $this->sessions->view(DB::table('platform_customers')->where('id', $customer->id)->first())];
    }

    public function logout(Request $request): array
    {
        DB::table('platform_customer_tokens')->where('id', $request->attributes->get('platform_customer')->session_token_id)->delete();
        return ['ok' => true];
    }

    public function updateAvatar(Request $request, PlatformCustomerAvatar $avatars): array
    {
        $request->validate(['image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:max_width=6000,max_height=6000']]);
        return $this->replaceAvatar($request, $avatars->store($request->file('image')));
    }

    public function deleteAvatar(Request $request): array { return $this->replaceAvatar($request, null); }

    private function replaceAvatar(Request $request, ?string $path): array
    {
        try {
            [$customer, $old] = DB::transaction(function () use ($request, $path) {
                $id = $request->attributes->get('platform_customer')->id;
                $customer = DB::table('platform_customers')->where('id', $id)->lockForUpdate()->first();
                $old = $customer->avatar_path;
                DB::table('platform_customers')->where('id', $id)->update(['avatar_path' => $path, 'updated_at' => now()]);
                $customer->avatar_path = $path;
                return [$customer, $old];
            });
        } catch (\Throwable $error) {
            if ($path) Storage::disk('local')->delete($path);
            throw $error;
        }
        if ($old) Storage::disk('local')->delete($old);
        return ['customer' => $this->sessions->view($customer)];
    }

    public function avatar(string $filename)
    {
        $path = 'platform-customer-avatars/'.$filename;
        abort_unless(DB::table('platform_customers')->where('avatar_path', $path)->where('is_active', true)->exists() && Storage::disk('local')->exists($path), 404);
        return response(Storage::disk('local')->get($path), 200, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'public, max-age=3600', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function orders(Request $request): array
    {
        $limit = max(1, min(48, (int) $request->query('limit', 24)));
        $filter = $request->validate(['status' => 'nullable|in:all,processing,completed,cancelled'])['status'] ?? 'all';
        $query = DB::table('ecommerce_orders as o')->join('storefront_settings as s', 's.business_id', '=', 'o.business_id')
            ->where('o.platform_customer_id', $request->attributes->get('platform_customer')->id);
        if ($filter === 'processing') $query->whereIn('o.status', ['pending', 'accepted', 'packing', 'ready_for_pickup', 'dispatched', 'return_pending']);
        if ($filter === 'completed') $query->where('o.status', 'completed');
        if ($filter === 'cancelled') $query->whereIn('o.status', ['cancelled', 'expired']);
        $rows = $query
            ->orderByDesc('o.id')->offset(max(0, (int) $request->query('offset', 0)))->limit($limit + 1)
            ->get(['o.id', 'o.business_id', 'o.reference', 'o.status', 'o.payment_status', 'o.total', 'o.currency', 'o.created_at', 's.display_name as store_name', 's.slug as store_slug']);
        $visible = $rows->take($limit)->values();
        $items = DB::table('ecommerce_order_items as i')->leftJoin('products as p', function ($join) {
            $join->on('p.id', '=', 'i.product_id')->on('p.business_id', '=', 'i.business_id');
        })->whereIn('i.order_id', $visible->pluck('id'))->orderBy('i.id')
            ->get(['i.order_id', 'i.business_id', 'i.product_id', 'i.name', 'p.photo_path'])->groupBy('order_id');
        foreach ($visible as $row) {
            $orderItems = ($items->get($row->id) ?? collect())->where('business_id', $row->business_id)->values();
            $row->item_count = $orderItems->count();
            $row->item_previews = $orderItems->take(3)->map(fn ($item) => [
                'name' => $item->name,
                'photo_url' => $item->photo_path ? url('/api/ecommerce/stores/'.$row->store_slug.'/products/'.$item->product_id.'/photo') : null,
            ])->values();
            unset($row->id, $row->business_id);
        }
        return ['items' => $visible, 'has_more' => $rows->count() > $limit];
    }

    public function order(Request $request, string $reference): array
    {
        $order = DB::table('ecommerce_orders')->where('reference', $reference)
            ->where('platform_customer_id', $request->attributes->get('platform_customer')->id)->first();
        abort_unless($order, 404, 'Order unavailable.');
        return $this->commerce->orderView($order) + ['tracking_credential' => $this->commerce->customerTrackingCredential($order)];
    }

    public function buyAgain(Request $request, string $reference): array
    {
        $order = DB::table('ecommerce_orders')->where('reference', $reference)
            ->where('platform_customer_id', $request->attributes->get('platform_customer')->id)->first();
        abort_unless($order, 404, 'Order unavailable.');
        $settings = DB::table('storefront_settings')->where('business_id', $order->business_id)->first();
        $store = $this->commerce->store($settings->slug);
        $previous = DB::table('ecommerce_order_items')->where('order_id', $order->id)->where('business_id', $order->business_id)->get();
        $products = $this->commerce->catalogQuery($store)->whereIn('p.id', $previous->pluck('product_id'))->get()->keyBy('id');
        $items = []; $skipped = [];
        foreach ($previous as $line) {
            $p = $products->get($line->product_id);
            if (!$p) { $skipped[] = $line->name; continue; }
            $view = $this->commerce->productView($store, $p);
            // Never reinterpret a historical quantity after publication units changed.
            if ($view['unit_name'] !== $line->unit_name || $this->commerce->milli($view['conversion_factor']) !== $this->commerce->milli($line->conversion_factor)) {
                $skipped[] = $line->name; continue;
            }
            $limit = min($view['available_quantity'], $view['flash_sale']['remaining_quantity'] ?? $view['available_quantity'], 100000);
            $quantity = min((float) $line->quantity, $limit);
            $factor = $this->commerce->milli($view['conversion_factor']);
            // Find the nearest supported quantity at or below the available limit.
            $milli = $this->commerce->milli($quantity);
            while ($milli > 0 && ($milli * $factor) % 1000 !== 0) $milli--;
            if (!$milli) { $skipped[] = $line->name; continue; }
            $items[] = $view + ['quantity' => $milli / 1000, 'previous_quantity' => (float) $line->quantity];
        }
        return ['slug' => $store->slug, 'name' => $store->display_name, 'origin' => 'storefront', 'items' => $items, 'skipped' => $skipped];
    }
}
